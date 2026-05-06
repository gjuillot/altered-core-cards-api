<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Excludes card groups whose related entity reference matches one of the given values.
 *
 * Usage on ManyToOne (e.g. cardType):
 *   ?cardType[ne][]=HERO  →  WHERE IDENTITY(o.cardType) NOT IN (id_of_HERO)
 *
 * Usage on ManyToMany (e.g. subTypes):
 *   ?subTypes[ne][]=HERO  →  WHERE o.id NOT IN (SELECT cg.id … JOIN subTypes WHERE ref IN (…))
 *
 * Register alongside ReferenceFilter with the same properties:
 *   #[ApiFilter(ExcludeReferenceFilter::class, properties: ['cardType', 'subTypes'])]
 */
final class ExcludeReferenceFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        // Expect ?cardType[ne][]=HERO  →  $value = ['ne' => ['HERO']]
        if (!$this->isPropertyEnabled($property, $resourceClass)) {
            return;
        }

        $refs = $this->extractRefs($value);
        if (empty($refs)) {
            return;
        }

        $alias    = $queryBuilder->getRootAliases()[0];
        $em       = $this->getManagerRegistry()->getManagerForClass($resourceClass);
        $metadata = $em->getClassMetadata($resourceClass);

        $targetClass = $metadata->getAssociationTargetClass($property);
        $lookupField = $this->getLookupField($property);

        $ids = $em->createQuery(
            sprintf('SELECT t.id FROM %s t WHERE t.%s IN (:refs)', $targetClass, $lookupField)
        )
            ->setParameter('refs', $refs)
            ->getSingleColumnResult();

        if (empty($ids)) {
            // Nothing to exclude — no-op.
            return;
        }

        $paramName = $queryNameGenerator->generateParameterName($property . '_ne');

        if ($metadata->isCollectionValuedAssociation($property)) {
            // ManyToMany: exclude root entities that have at least one of these sub-entities.
            $subAlias = $queryNameGenerator->generateJoinAlias($property);
            $queryBuilder
                ->andWhere(sprintf(
                    '%s.id NOT IN (SELECT IDENTITY(%s_sub.id) FROM %s %s_sub JOIN %s_sub.%s %s WHERE %s.id IN (:%s))',
                    $alias,
                    $subAlias,
                    $resourceClass,
                    $subAlias,
                    $subAlias,
                    $property,
                    $subAlias,
                    $subAlias,
                    $paramName,
                ))
                ->setParameter($paramName, $ids);
        } else {
            // ManyToOne / OneToOne: simple NOT IN on the FK.
            $queryBuilder
                ->andWhere(sprintf('IDENTITY(%s.%s) NOT IN (:%s)', $alias, $property, $paramName))
                ->setParameter($paramName, $ids);
        }
    }

    public function getDescription(string $resourceClass): array
    {
        $description = [];

        foreach ($this->properties ?? [] as $key => $val) {
            $property = is_string($key) ? $key : (string) $val;

            $description["{$property}[ne][]"] = [
                'property'    => $property,
                'type'        => 'string',
                'required'    => false,
                'is_collection' => true,
                'description' => sprintf('Exclude by %s reference (repeatable)', $property),
            ];
        }

        return $description;
    }

    /** Extract refs from ?prop[ne][]=A&prop[ne][]=B  →  ['A', 'B']. */
    private function extractRefs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ne = $value['ne'] ?? null;

        if ($ne === null) {
            return [];
        }

        return array_values(array_filter((array) $ne, fn($v) => $v !== null && $v !== ''));
    }

    private function getLookupField(string $property): string
    {
        foreach ($this->properties ?? [] as $key => $val) {
            if (is_string($key) && $key === $property) {
                return is_string($val) && $val !== '' ? $val : 'reference';
            }
        }

        return 'reference';
    }
}
