<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Card;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters CardGroup by card reference using an EXISTS subquery instead of a JOIN,
 * avoiding row multiplication from the cards OneToMany relation.
 *
 * Usage: ?cards.reference[]=ALT_CORE_B_AX_1_C&cards.reference[]=ALT_CORE_B_AX_2_C
 */
final class CardGroupCardReferenceFilter extends AbstractFilter
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
        if ($property !== 'cards.reference' || $value === null || $value === '') {
            return;
        }

        $root   = $queryBuilder->getRootAliases()[0];
        $cAlias = $queryNameGenerator->generateJoinAlias('card');
        $p      = $queryNameGenerator->generateParameterName('card_ref');

        $subDql = sprintf(
            'SELECT IDENTITY(%s.cardGroup) FROM %s %s WHERE %s.reference IN (:%s)',
            $cAlias,
            Card::class, $cAlias,
            $cAlias, $p,
        );

        $queryBuilder
            ->andWhere("$root.id IN ($subDql)")
            ->setParameter($p, (array) $value);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'cards.reference[]' => [
                'property' => 'cards.reference',
                'type'     => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => 'Filter by card reference (supports multiple values)',
            ],
        ];
    }
}
