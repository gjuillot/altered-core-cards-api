<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Co-occurrence queries on card_search for slot-composition cascading.
 *
 * All queries use UNION (not UNION ALL) to deduplicate (card_id, value_id)
 * pairs across slots — each card counts once per unique value.
 */
final class SlotFacetRepository
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Returns conditions that appear in the same slot as the given trigger.
     * Result: [alteredId => cardCount]
     */
    public function conditionsForTrigger(int $triggerAlteredId): array
    {
        $sql = <<<SQL
            WITH t AS (SELECT id FROM ability_trigger WHERE altered_id = :altId)
            SELECT ac.altered_id, COUNT(DISTINCT pairs.card_id) AS cnt
            FROM (
                SELECT card_id, c1  AS cid FROM card_search WHERE t1  = (SELECT id FROM t) AND c1  IS NOT NULL
                UNION
                SELECT card_id, c2  FROM card_search WHERE t2  = (SELECT id FROM t) AND c2  IS NOT NULL
                UNION
                SELECT card_id, c3  FROM card_search WHERE t3  = (SELECT id FROM t) AND c3  IS NOT NULL
                UNION
                SELECT card_id, ec1 FROM card_search WHERE et1 = (SELECT id FROM t) AND ec1 IS NOT NULL
            ) pairs
            JOIN ability_condition ac ON ac.id = pairs.cid
            GROUP BY ac.altered_id
            ORDER BY cnt DESC
        SQL;

        return $this->fetchAlteredIdCounts($sql, $triggerAlteredId);
    }

    /**
     * Returns effects that appear in the same slot as the given trigger.
     * If conditionAlteredId is provided, also requires the condition to match.
     * Result: [alteredId => cardCount]
     */
    public function effectsForTrigger(int $triggerAlteredId, ?int $conditionAlteredId = null): array
    {
        if ($conditionAlteredId !== null) {
            $sql = <<<SQL
                WITH t AS (SELECT id FROM ability_trigger   WHERE altered_id = :altId),
                     c AS (SELECT id FROM ability_condition WHERE altered_id = :condId)
                SELECT ae.altered_id, COUNT(DISTINCT pairs.card_id) AS cnt
                FROM (
                    SELECT card_id, e1  AS eid FROM card_search WHERE t1  = (SELECT id FROM t) AND c1  = (SELECT id FROM c) AND e1  IS NOT NULL
                    UNION
                    SELECT card_id, e2  FROM card_search WHERE t2  = (SELECT id FROM t) AND c2  = (SELECT id FROM c) AND e2  IS NOT NULL
                    UNION
                    SELECT card_id, e3  FROM card_search WHERE t3  = (SELECT id FROM t) AND c3  = (SELECT id FROM c) AND e3  IS NOT NULL
                    UNION
                    SELECT card_id, ee1 FROM card_search WHERE et1 = (SELECT id FROM t) AND ec1 = (SELECT id FROM c) AND ee1 IS NOT NULL
                ) pairs
                JOIN ability_effect ae ON ae.id = pairs.eid
                GROUP BY ae.altered_id
                ORDER BY cnt DESC
            SQL;

            $rows = $this->connection->fetchAllAssociative($sql, [
                'altId'  => $triggerAlteredId,
                'condId' => $conditionAlteredId,
            ]);

            return array_column($rows, 'cnt', 'altered_id');
        }

        $sql = <<<SQL
            WITH t AS (SELECT id FROM ability_trigger WHERE altered_id = :altId)
            SELECT ae.altered_id, COUNT(DISTINCT pairs.card_id) AS cnt
            FROM (
                SELECT card_id, e1  AS eid FROM card_search WHERE t1  = (SELECT id FROM t) AND e1  IS NOT NULL
                UNION
                SELECT card_id, e2  FROM card_search WHERE t2  = (SELECT id FROM t) AND e2  IS NOT NULL
                UNION
                SELECT card_id, e3  FROM card_search WHERE t3  = (SELECT id FROM t) AND e3  IS NOT NULL
                UNION
                SELECT card_id, ee1 FROM card_search WHERE et1 = (SELECT id FROM t) AND ee1 IS NOT NULL
            ) pairs
            JOIN ability_effect ae ON ae.id = pairs.eid
            GROUP BY ae.altered_id
            ORDER BY cnt DESC
        SQL;

        return $this->fetchAlteredIdCounts($sql, $triggerAlteredId);
    }

    /**
     * Returns triggers that appear in the same slot as the given condition.
     * Result: [alteredId => cardCount]
     */
    public function triggersForCondition(int $conditionAlteredId): array
    {
        $sql = <<<SQL
            WITH c AS (SELECT id FROM ability_condition WHERE altered_id = :altId)
            SELECT at.altered_id, COUNT(DISTINCT pairs.card_id) AS cnt
            FROM (
                SELECT card_id, t1  AS tid FROM card_search WHERE c1  = (SELECT id FROM c) AND t1  IS NOT NULL
                UNION
                SELECT card_id, t2  FROM card_search WHERE c2  = (SELECT id FROM c) AND t2  IS NOT NULL
                UNION
                SELECT card_id, t3  FROM card_search WHERE c3  = (SELECT id FROM c) AND t3  IS NOT NULL
                UNION
                SELECT card_id, et1 FROM card_search WHERE ec1 = (SELECT id FROM c) AND et1 IS NOT NULL
            ) pairs
            JOIN ability_trigger at ON at.id = pairs.tid
            GROUP BY at.altered_id
            ORDER BY cnt DESC
        SQL;

        return $this->fetchAlteredIdCounts($sql, $conditionAlteredId);
    }

    /**
     * Returns effects that appear in the same slot as the given condition.
     * Result: [alteredId => cardCount]
     */
    public function effectsForCondition(int $conditionAlteredId): array
    {
        $sql = <<<SQL
            WITH c AS (SELECT id FROM ability_condition WHERE altered_id = :altId)
            SELECT ae.altered_id, COUNT(DISTINCT pairs.card_id) AS cnt
            FROM (
                SELECT card_id, e1  AS eid FROM card_search WHERE c1  = (SELECT id FROM c) AND e1  IS NOT NULL
                UNION
                SELECT card_id, e2  FROM card_search WHERE c2  = (SELECT id FROM c) AND e2  IS NOT NULL
                UNION
                SELECT card_id, e3  FROM card_search WHERE c3  = (SELECT id FROM c) AND e3  IS NOT NULL
                UNION
                SELECT card_id, ee1 FROM card_search WHERE ec1 = (SELECT id FROM c) AND ee1 IS NOT NULL
            ) pairs
            JOIN ability_effect ae ON ae.id = pairs.eid
            GROUP BY ae.altered_id
            ORDER BY cnt DESC
        SQL;

        return $this->fetchAlteredIdCounts($sql, $conditionAlteredId);
    }

    /**
     * Returns triggers that appear in the same slot as the given effect.
     * Result: [alteredId => cardCount]
     */
    public function triggersForEffect(int $effectAlteredId): array
    {
        $sql = <<<SQL
            WITH e AS (SELECT id FROM ability_effect WHERE altered_id = :altId)
            SELECT at.altered_id, COUNT(DISTINCT pairs.card_id) AS cnt
            FROM (
                SELECT card_id, t1  AS tid FROM card_search WHERE e1  = (SELECT id FROM e) AND t1  IS NOT NULL
                UNION
                SELECT card_id, t2  FROM card_search WHERE e2  = (SELECT id FROM e) AND t2  IS NOT NULL
                UNION
                SELECT card_id, t3  FROM card_search WHERE e3  = (SELECT id FROM e) AND t3  IS NOT NULL
                UNION
                SELECT card_id, et1 FROM card_search WHERE ee1 = (SELECT id FROM e) AND et1 IS NOT NULL
            ) pairs
            JOIN ability_trigger at ON at.id = pairs.tid
            GROUP BY at.altered_id
            ORDER BY cnt DESC
        SQL;

        return $this->fetchAlteredIdCounts($sql, $effectAlteredId);
    }

    /**
     * Returns conditions that appear in the same slot as the given effect.
     * Result: [alteredId => cardCount]
     */
    public function conditionsForEffect(int $effectAlteredId): array
    {
        $sql = <<<SQL
            WITH e AS (SELECT id FROM ability_effect WHERE altered_id = :altId)
            SELECT ac.altered_id, COUNT(DISTINCT pairs.card_id) AS cnt
            FROM (
                SELECT card_id, c1  AS cid FROM card_search WHERE e1  = (SELECT id FROM e) AND c1  IS NOT NULL
                UNION
                SELECT card_id, c2  FROM card_search WHERE e2  = (SELECT id FROM e) AND c2  IS NOT NULL
                UNION
                SELECT card_id, c3  FROM card_search WHERE e3  = (SELECT id FROM e) AND c3  IS NOT NULL
                UNION
                SELECT card_id, ec1 FROM card_search WHERE ee1 = (SELECT id FROM e) AND ec1 IS NOT NULL
            ) pairs
            JOIN ability_condition ac ON ac.id = pairs.cid
            GROUP BY ac.altered_id
            ORDER BY cnt DESC
        SQL;

        return $this->fetchAlteredIdCounts($sql, $effectAlteredId);
    }

    /**
     * Returns all slot compositions (trigger+condition+effect) present in the given card IDs.
     * Each row = one unique T+C+E combination with the count of cards having it.
     *
     * @param  int[]  $cardIds
     * @return array<int, array{trigger: int|null, condition: int|null, effect: int|null, count: int}>
     */
    public function slotCompositionsForCards(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $ids = implode(',', array_map('intval', $cardIds));

        $sql = <<<SQL
            WITH slots AS (
                SELECT card_id, t1  AS tid, c1  AS cid, e1  AS eid FROM card_search WHERE card_id IN ($ids) AND t1  IS NOT NULL
                UNION ALL
                SELECT card_id, t2,         c2,         e2          FROM card_search WHERE card_id IN ($ids) AND t2  IS NOT NULL
                UNION ALL
                SELECT card_id, t3,         c3,         e3          FROM card_search WHERE card_id IN ($ids) AND t3  IS NOT NULL
                UNION ALL
                SELECT card_id, et1,        ec1,        ee1         FROM card_search WHERE card_id IN ($ids) AND et1 IS NOT NULL
            )
            SELECT
                at.altered_id                    AS trigger,
                ac.altered_id                    AS condition,
                ae.altered_id                    AS effect,
                COUNT(DISTINCT s.card_id)::int   AS count
            FROM slots s
            LEFT JOIN ability_trigger   at ON at.id = s.tid
            LEFT JOIN ability_condition ac ON ac.id = s.cid
            LEFT JOIN ability_effect    ae ON ae.id = s.eid
            GROUP BY at.altered_id, ac.altered_id, ae.altered_id
            ORDER BY count DESC
        SQL;

        return $this->connection->fetchAllAssociative($sql);
    }

    private function fetchAlteredIdCounts(string $sql, int $alteredId): array
    {
        $rows = $this->connection->fetchAllAssociative($sql, ['altId' => $alteredId]);
        return array_column($rows, 'cnt', 'altered_id');
    }
}
