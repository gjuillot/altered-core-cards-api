<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Card;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters CardGroup by whether any of its cards has transfuge = true/false.
 *
 * Usage: ?transfuge=true | ?transfuge=false
 */
final class CardGroupTransfugeFilter extends AbstractFilter
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
        if ($property !== 'transfuge' || $value === null || $value === '') {
            return;
        }

        $bool   = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $root   = $queryBuilder->getRootAliases()[0];
        $cAlias = $queryNameGenerator->generateJoinAlias('card');
        $p      = $queryNameGenerator->generateParameterName('transfuge');

        $subDql = sprintf(
            'SELECT IDENTITY(%s.cardGroup) FROM %s %s WHERE %s.transfuge = :%s',
            $cAlias, Card::class, $cAlias, $cAlias, $p,
        );

        $queryBuilder
            ->andWhere("$root.id IN ($subDql)")
            ->setParameter($p, $bool);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'transfuge' => [
                'property'    => 'transfuge',
                'type'        => 'bool',
                'required'    => false,
                'description' => 'Filter by transfuge flag',
            ],
        ];
    }
}
