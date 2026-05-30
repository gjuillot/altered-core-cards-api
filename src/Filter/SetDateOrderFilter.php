<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Maps order[set.date] to the local card.setDate column, avoiding a JOIN to card_set.
 * card.setDate is always equal to card.set.date (copied on setSet()), so results are identical
 * but PostgreSQL can use idx_card_set_date_collector instead of joining card_set.
 */
final class SetDateOrderFilter extends AbstractFilter
{
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $order = $context['filters']['order'] ?? [];
        $dir   = strtoupper((string) ($order['set.date'] ?? ''));

        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->addOrderBy("$alias.setDate", $dir);
    }

    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {}

    public function getDescription(string $resourceClass): array
    {
        return [
            'order[set.date]' => [
                'property' => 'setDate',
                'type'     => 'string',
                'required' => false,
                'schema'   => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ],
        ];
    }
}
