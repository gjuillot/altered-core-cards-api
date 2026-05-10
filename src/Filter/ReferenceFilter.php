<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Resolves relation lookup fields to IDs before the main query, so PostgreSQL
 * can use FK indexes directly instead of generating a Nested Loop subquery.
 *
 * Default usage — looks up by `reference` field:
 *   #[ApiFilter(ReferenceFilter::class, properties: ['rarity', 'cardType'])]
 *   → ?rarity=COMMON  →  WHERE rarity_id IN (2)
 *
 * Custom lookup field — use a keyed property with the field name as value:
 *   #[ApiFilter(ReferenceFilter::class, properties: ['faction' => 'code'])]
 *   → ?faction=LY  →  WHERE faction_id IN (3)
 */
final class ReferenceFilter extends AbstractFilter
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
        if (!$this->isPropertyEnabled($property, $resourceClass) || $value === null || $value === '') {
            return;
        }

        // Mixed array (e.g. [0=>'LANDMARK_PERMANENT', 'ne'=>['HERO']]): keep only the
        // integer-keyed (positive) values; the 'ne' part is handled by ExcludeReferenceFilter.
        if (is_array($value) && !array_is_list($value)) {
            $value = array_values(array_filter($value, fn($v, $k) => is_int($k) && $v !== '', ARRAY_FILTER_USE_BOTH));
            if (empty($value)) {
                return;
            }
        }

        $alias = $queryBuilder->getRootAliases()[0];

        $em          = $this->getManagerRegistry()->getManagerForClass($resourceClass);
        $metadata    = $em->getClassMetadata($resourceClass);
        $targetClass = $metadata->getAssociationTargetClass($property);
        $lookupField = $this->getLookupField($property);

        $ids = $em->createQuery(sprintf('SELECT t.id FROM %s t WHERE t.%s IN (:refs)', $targetClass, $lookupField))
            ->setParameter('refs', (array) $value)
            ->getSingleColumnResult();

        if (empty($ids)) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        $paramName = $queryNameGenerator->generateParameterName($property);

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.%s) IN (:%s)', $alias, $property, $paramName))
            ->setParameter($paramName, $ids);
    }

    public function getDescription(string $resourceClass): array
    {
        $description = [];

        foreach ($this->properties ?? [] as $key => $val) {
            $property    = is_string($key) ? $key : (string) $val;
            $lookupField = is_string($key) && is_string($val) && $val !== '' ? $val : 'reference';

            $description[$property] = [
                'property'    => $property,
                'type'        => 'string',
                'required'    => false,
                'description' => sprintf('Filter by %s %s', $property, $lookupField),
            ];
        }

        return $description;
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
