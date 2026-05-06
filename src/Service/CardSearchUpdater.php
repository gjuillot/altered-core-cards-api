<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Maintains the card_search flat table used for fast effect/keyword filtering.
 *
 * Schema:
 *   card_search(card_id PK, t1,c1,e1, t2,c2,e2, t3,c3,e3, has_effect BOOL, keywords TEXT[])
 */
final class CardSearchUpdater
{
    /**
     * SQL fragment shared by upsert operations.
     * Joins card → card_group → main_effect x3 and extracts denormalized data.
     */
    private const SELECT_SQL = "
        SELECT
            c.id                         AS card_id,
            me1.ability_trigger_id       AS t1,
            me1.ability_condition_id     AS c1,
            me1.ability_effect_id        AS e1,
            me2.ability_trigger_id       AS t2,
            me2.ability_condition_id     AS c2,
            me2.ability_effect_id        AS e2,
            me3.ability_trigger_id       AS t3,
            me3.ability_condition_id     AS c3,
            me3.ability_effect_id        AS e3,
            (me1.id IS NOT NULL OR me2.id IS NOT NULL OR me3.id IS NOT NULL) AS has_effect,
            c.is_public                  AS is_public,
            COALESCE(
                (SELECT array_agg(DISTINCT kw)
                 FROM (
                     SELECT jsonb_array_elements(COALESCE(me1.keywords, '[]')::jsonb)->>'k' AS kw
                     UNION ALL
                     SELECT jsonb_array_elements(COALESCE(me2.keywords, '[]')::jsonb)->>'k' AS kw
                     UNION ALL
                     SELECT jsonb_array_elements(COALESCE(me3.keywords, '[]')::jsonb)->>'k' AS kw
                 ) kws
                 WHERE kw IS NOT NULL
                ),
                '{}'
            ) AS keywords
        FROM card c
        LEFT JOIN card_group cg ON cg.id = c.card_group_id
        LEFT JOIN main_effect me1 ON me1.id = cg.effect1_id
        LEFT JOIN main_effect me2 ON me2.id = cg.effect2_id
        LEFT JOIN main_effect me3 ON me3.id = cg.effect3_id
    ";

    private const UPSERT_SUFFIX = "
        ON CONFLICT (card_id) DO UPDATE SET
            t1 = EXCLUDED.t1, c1 = EXCLUDED.c1, e1 = EXCLUDED.e1,
            t2 = EXCLUDED.t2, c2 = EXCLUDED.c2, e2 = EXCLUDED.e2,
            t3 = EXCLUDED.t3, c3 = EXCLUDED.c3, e3 = EXCLUDED.e3,
            has_effect = EXCLUDED.has_effect,
            is_public  = EXCLUDED.is_public,
            keywords   = EXCLUDED.keywords
    ";

    public function __construct(private readonly Connection $connection) {}

    public function upsertCard(int $cardId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO card_search (card_id,t1,c1,e1,t2,c2,e2,t3,c3,e3,has_effect,is_public,keywords)'
            . self::SELECT_SQL
            . ' WHERE c.id = :cardId'
            . self::UPSERT_SUFFIX,
            ['cardId' => $cardId],
        );
    }

    public function upsertByCardGroupId(int $cardGroupId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO card_search (card_id,t1,c1,e1,t2,c2,e2,t3,c3,e3,has_effect,is_public,keywords)'
            . self::SELECT_SQL
            . ' WHERE cg.id = :cgId'
            . self::UPSERT_SUFFIX,
            ['cgId' => $cardGroupId],
        );
    }

    public function upsertByMainEffectId(int $mainEffectId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO card_search (card_id,t1,c1,e1,t2,c2,e2,t3,c3,e3,has_effect,is_public,keywords)'
            . self::SELECT_SQL
            . ' WHERE cg.effect1_id = :meId OR cg.effect2_id = :meId OR cg.effect3_id = :meId'
            . self::UPSERT_SUFFIX,
            ['meId' => $mainEffectId],
        );
    }

    public function deleteCard(int $cardId): void
    {
        $this->connection->executeStatement('DELETE FROM card_search WHERE card_id = :id', ['id' => $cardId]);
    }

    /**
     * Full rebuild — call from BuildCardSearchCommand.
     * Returns number of rows inserted.
     */
    public function rebuild(): int
    {
        $this->connection->executeStatement('TRUNCATE card_search');

        return (int) $this->connection->executeStatement(
            'INSERT INTO card_search (card_id,t1,c1,e1,t2,c2,e2,t3,c3,e3,has_effect,is_public,keywords)'
            . self::SELECT_SQL,
        );
    }
}
