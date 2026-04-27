<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Keyset pagination cursor: ?afterId=N returns items where id > N, ordered by id ASC.
 *
 * Use with page=1 and itemsPerPage=1000 to avoid deep OFFSET scans.
 * The client takes the last item's id from each page and passes it as afterId for the next page.
 */
final class AfterIdFilter extends AbstractFilter
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
        if ($property !== 'afterId' || $value === null || $value === '') {
            return;
        }

        $afterId = (int) $value;
        if ($afterId <= 0) {
            return;
        }

        $alias     = $queryBuilder->getRootAliases()[0];
        $paramName = $queryNameGenerator->generateParameterName('afterId');

        $queryBuilder
            ->andWhere(sprintf('%s.id > :%s', $alias, $paramName))
            ->setParameter($paramName, $afterId);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'afterId' => [
                'property'    => 'id',
                'type'        => 'integer',
                'required'    => false,
                'description' => 'Keyset cursor: return only items with id > afterId. Use with page=1 to avoid deep OFFSET scans.',
            ],
        ];
    }
}
