<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Counts cards matching API Platform filter parameters via raw DBAL.
 * Used by SearchAwareCollectionProvider to cache accurate filtered totals
 * instead of relying on Meilisearch's estimatedTotalHits (which can be off
 * by ~0.2 %, generating phantom pages at the end of a dataset).
 */
final class FilteredCardCountRepository
{
    /** Maps API Platform filter key → [alias, column, join keys needed] */
    private const COL = [
        'variation'     => ['c',   'variation',    []],
        'isSerialized'  => ['c',   'is_serialized', []],
        'promo'         => ['c',   'promo',          []],
        'kickstarter'   => ['c',   'kickstarter',    []],
        'mainCost'      => ['cg',  'main_cost',      ['cg']],
        'recallCost'    => ['cg',  'recall_cost',    ['cg']],
        'isBanned'      => ['cg',  'is_banned',      ['cg']],
        'isSuspended'   => ['cg',  'is_suspended',   ['cg']],
        'isErrated'     => ['cg',  'is_errated',     ['cg']],
        'rarity'        => ['r',   'reference',      ['cg', 'r']],
        'faction.code'  => ['f',   'code',           ['cg', 'f']],
        'set.reference' => ['cs',  'reference',      ['cs']],
        'cardType'      => ['ct',  'reference',      ['cg', 'ct']],
        'subTypes'      => ['cst', 'reference',      ['cg', 'cgsl', 'cst']],
    ];

    public function __construct(private readonly Connection $connection) {}

    public function count(array $filters): int
    {
        $qb    = $this->connection->createQueryBuilder()
            ->select('COUNT(DISTINCT c.id)')
            ->from('card', 'c');

        $joins = [];
        $i     = 0;

        foreach ($filters as $key => $raw) {
            if (!isset(self::COL[$key])) {
                continue;
            }
            $values = array_values(array_filter((array) $raw, fn($v) => $v !== '' && $v !== null));
            if (empty($values)) {
                continue;
            }

            [$alias, $col, $required] = self::COL[$key];
            foreach ($required as $j) {
                $joins[$j] = true;
            }

            $p = 'p' . $i++;

            // Range operators: mainCost[lte]=5 arrives as ['lte' => '5']
            if (!array_is_list($raw) && is_array($raw)) {
                static $rangeOps = ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];
                foreach ($raw as $op => $val) {
                    $sqlOp = $rangeOps[$op] ?? null;
                    if ($sqlOp !== null && $val !== '' && $val !== null) {
                        $qb->andWhere("$alias.$col $sqlOp :$p")->setParameter($p, $val);
                        $p = 'p' . $i++;
                    }
                }
                continue;
            }

            if (count($values) === 1) {
                $qb->andWhere("$alias.$col = :$p")->setParameter($p, $values[0]);
            } else {
                $qb->andWhere("$alias.$col IN (:$p)")->setParameter($p, $values, ArrayParameterType::STRING);
            }
        }

        // Joins are added in dependency order (parent before child alias).
        if (isset($joins['cg'])) {
            $qb->leftJoin('c', 'card_group', 'cg', 'cg.id = c.card_group_id');
        }
        if (isset($joins['r'])) {
            $qb->leftJoin('cg', 'rarity', 'r', 'r.id = cg.rarity_id');
        }
        if (isset($joins['f'])) {
            $qb->leftJoin('cg', 'faction', 'f', 'f.id = cg.faction_id');
        }
        if (isset($joins['cs'])) {
            $qb->leftJoin('c', 'card_set', 'cs', 'cs.id = c.set_id');
        }
        if (isset($joins['ct'])) {
            $qb->leftJoin('cg', 'card_type', 'ct', 'ct.id = cg.card_type_id');
        }
        if (isset($joins['cgsl'])) {
            $qb->leftJoin('cg', 'card_group_sub_type_link', 'cgsl', 'cgsl.card_group_id = cg.id');
        }
        if (isset($joins['cst'])) {
            $qb->leftJoin('cgsl', 'card_sub_type', 'cst', 'cst.id = cgsl.card_sub_type_id');
        }

        return (int) $qb->executeQuery()->fetchOne();
    }
}
