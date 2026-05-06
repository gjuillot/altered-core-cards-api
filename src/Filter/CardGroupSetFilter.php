<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Card;
use App\Entity\Set;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters CardGroup by set reference using an EXISTS subquery instead of a JOIN,
 * avoiding row multiplication from the cards OneToMany relation.
 *
 * Usage: ?set.reference=COREKS
 */
final class CardGroupSetFilter extends AbstractFilter
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
        if ($property !== 'set.reference' || $value === null || $value === '') {
            return;
        }

        $root   = $queryBuilder->getRootAliases()[0];
        $cAlias = $queryNameGenerator->generateJoinAlias('card');
        $sAlias = $queryNameGenerator->generateJoinAlias('set');
        $p      = $queryNameGenerator->generateParameterName('set_ref');

        // Non-correlated IN subquery: avoids the alias confusion that occurs when
        // API Platform wraps the query in a double-nested COUNT (CountOutputWalker),
        // where a correlated EXISTS referencing $root maps to the outer alias instead
        // of the inner one, producing wrong results and killing performance.
        $subDql = sprintf(
            'SELECT IDENTITY(%s.cardGroup) FROM %s %s JOIN %s.set %s WHERE %s.reference IN (:%s)',
            $cAlias,
            Card::class, $cAlias,
            $cAlias, $sAlias,
            $sAlias, $p,
        );

        $queryBuilder
            ->andWhere("$root.id IN ($subDql)")
            ->setParameter($p, (array) $value);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'set.reference' => [
                'property' => 'set.reference',
                'type'     => 'string',
                'required' => false,
                'description' => 'Filter by set reference (e.g. set.reference=COREKS)',
            ],
        ];
    }
}
