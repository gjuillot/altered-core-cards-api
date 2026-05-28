<?php

namespace App\Filter;

use Doctrine\ORM\QueryBuilder;

/**
 * Safely applies a large IN clause of integer card IDs without hitting
 * PostgreSQL's 65535 bind-parameter limit.
 *
 * IDs are cast to int and inlined directly into the SQL expression.
 * This is safe because they are guaranteed integers from card_search.
 */
trait CardSearchInClauseTrait
{
    private function applyIdInClause(QueryBuilder $qb, string $alias, array $ids): void
    {
        if (empty($ids)) {
            $qb->andWhere('1 = 0');
            return;
        }

        $intIds = implode(',', array_map('intval', $ids));
        $qb->andWhere("$alias.id IN ($intIds)");
    }

    private function resolveAlteredId(string $table, int $alteredId): int
    {
        if ($alteredId === 0) {
            return 0;
        }
        $conn = $this->managerRegistry->getManager()->getConnection();
        return (int) ($conn->fetchOne("SELECT id FROM $table WHERE altered_id = ?", [$alteredId]) ?: 0);
    }

    /**
     * Batch-resolve multiple alteredIds for one table.
     * Returns a map of [alteredId => internalId].
     *
     * @param  int[]  $alteredIds
     * @return array<int,int>
     */
    private function resolveAlteredIds(string $table, array $alteredIds): array
    {
        $alteredIds = array_values(array_unique(array_filter(array_map('intval', $alteredIds))));
        if (empty($alteredIds)) {
            return [];
        }
        $placeholders = implode(',', $alteredIds);
        $conn         = $this->managerRegistry->getManager()->getConnection();
        $rows         = $conn->fetchAllNumeric(
            "SELECT altered_id, id FROM $table WHERE altered_id IN ($placeholders)"
        );
        $map = [];
        foreach ($rows as [$alteredId, $id]) {
            $map[(int) $alteredId] = (int) $id;
        }
        return $map;
    }
}
