<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters cards by the relationship between mainCost and recallCost.
 *
 * ?costRelation=equal        → mainCost = recallCost
 * ?costRelation=mainHigher   → mainCost > recallCost
 * ?costRelation=recallHigher → recallCost > mainCost
 */
final class CostRelationFilter extends AbstractFilter
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
        if (!$this->isPropertyEnabled($property, $resourceClass) || empty($value)) {
            return;
        }

        $root    = $queryBuilder->getRootAliases()[0];
        $through = $this->properties[$property] ?? null;

        if ($through) {
            $cgAlias = $queryNameGenerator->generateJoinAlias($through);
            $queryBuilder->leftJoin("$root.$through", $cgAlias);
        } else {
            $cgAlias = $root;
        }

        match ($value) {
            'equal'        => $queryBuilder->andWhere("$cgAlias.mainCost = $cgAlias.recallCost"),
            'mainHigher'   => $queryBuilder->andWhere("$cgAlias.mainCost > $cgAlias.recallCost"),
            'recallHigher' => $queryBuilder->andWhere("$cgAlias.recallCost > $cgAlias.mainCost"),
            default        => null,
        };
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'costRelation' => [
                'property'    => 'costRelation',
                'type'        => 'string',
                'required'    => false,
                'description' => 'equal | mainHigher | recallHigher',
            ],
        ];
    }
}
