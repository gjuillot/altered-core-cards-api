<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Builds raw card documents for search indexing.
 * Single source of truth for the DBAL query — no SQL elsewhere.
 */
final class CardDocumentRepository
{
    /** Fields that require joined tables — cannot be selected directly from card. */
    private const EFFECT_FIELDS = [
        'slot1_trigger', 'slot1_condition', 'slot1_effect',
        'slot2_trigger', 'slot2_condition', 'slot2_effect',
        'slot3_trigger', 'slot3_condition', 'slot3_effect',
        'echo_trigger',  'echo_condition',  'echo_effect',
        'trigger_repeat_count', 'has_effect', 'keywords',
        'transfuge',
    ];

    public function __construct(private readonly Connection $connection) {}

    /**
     * Stream only specific card columns.
     * Use for partial Meilisearch updates when only a few fields changed.
     *
     * @param string[] $fields
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function streamPartialDocuments(array $fields, int $batchSize = 2000): \Generator
    {
        $needsCostRelation = in_array('cost_relation', $fields, true);
        $needsEffects      = (bool) array_intersect($fields, self::EFFECT_FIELDS);
        $directFields      = array_filter($fields, fn($f) => $f !== 'cost_relation' && !in_array($f, self::EFFECT_FIELDS, true));

        $cols  = $directFields ? implode(', ', array_map(fn($f) => "c.$f AS $f", $directFields)) : '';
        $joins = [];

        if ($needsCostRelation || $needsEffects) {
            $joins[] = 'LEFT JOIN card_group cg ON cg.id = c.card_group_id';
            $cols   .= ($cols !== '' ? ', ' : '') . 'cg.main_cost, cg.recall_cost';
        }

        if ($needsEffects) {
            $joins[] = 'LEFT JOIN main_effect me1 ON me1.id = cg.effect1_id';
            $joins[] = 'LEFT JOIN main_effect me2 ON me2.id = cg.effect2_id';
            $joins[] = 'LEFT JOIN main_effect me3 ON me3.id = cg.effect3_id';
            $joins[] = 'LEFT JOIN main_effect mee ON mee.id = cg.echo_effect1_id';
            $joins[] = 'LEFT JOIN card_search cks ON cks.card_id = c.id';
            $cols   .= ', ' . $this->effectSlotColumns() . ', cks.has_effect, cks.keywords, c.transfuge';
        }

        $joinSql = implode(' ', $joins);
        $result  = $this->connection->executeQuery("SELECT c.id, $cols FROM card c $joinSql ORDER BY c.id");

        $batch = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $doc = ['id' => (int) $row['id']] + array_intersect_key($row, array_flip($directFields));

            if ($needsCostRelation || $needsEffects) {
                $doc['cost_relation'] = $this->computeCostRelation($row['main_cost'], $row['recall_cost']);
            }

            if ($needsEffects) {
                $doc += $this->hydrateEffectFields($row);
            }

            $batch[] = $doc;

            if (count($batch) >= $batchSize) {
                yield $batch;
                $batch = [];
            }
        }

        if (!empty($batch)) {
            yield $batch;
        }
    }

    /**
     * Stream all card documents as flat arrays, batched for memory efficiency.
     *
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function streamDocuments(int $batchSize = 2000): \Generator
    {
        $result = $this->connection->executeQuery($this->buildSql());

        $batch = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $batch[] = $this->hydrate($row);

            if (count($batch) >= $batchSize) {
                yield $batch;
                $batch = [];
            }
        }

        if (!empty($batch)) {
            yield $batch;
        }
    }

    /**
     * Build a single card document by ID.
     * Returns null if the card does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function findDocument(int $cardId): ?array
    {
        $row = $this->connection->executeQuery(
            $this->buildSql(whereCardId: true),
            ['id' => $cardId]
        )->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Count total cards (for progress bars, etc.).
     */
    public function countAll(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM card');
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function sanitize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $encoded = json_encode($text, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        $clean = json_decode($encoded);
        if (!is_string($clean)) {
            return null;
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
    }

    private function buildSql(bool $whereCardId = false): string
    {
        $where      = $whereCardId ? 'WHERE c.id = :id' : '';
        $effectCols = $this->effectSlotColumns();

        return <<<SQL
            SELECT
                c.id,
                c.reference,
                c.kickstarter,
                c.promo,
                c.transfuge,
                c.is_serialized,
                c.variation,
                c.collector_number_formated_id,
                COALESCE(c.set_date, cs.date::date)                                   AS set_date,
                cs.reference                                                          AS set_reference,
                cg.main_cost,
                cg.recall_cost,
                cg.ocean_power,
                cg.mountain_power,
                cg.forest_power,
                cg.is_banned,
                cg.is_suspended,
                cg.is_errated,
                f.code                                                                AS faction_code,
                r.reference                                                           AS rarity,
                ct.reference                                                          AS card_type,
                $effectCols,
                cks.has_effect,
                cks.keywords,
                MAX(CASE WHEN cgt.locale = 'fr' THEN cgt.name        END)            AS name_fr,
                MAX(CASE WHEN cgt.locale = 'en' THEN cgt.name        END)            AS name_en,
                MAX(CASE WHEN cgt.locale = 'fr' THEN cgt.main_effect END)            AS main_effect_fr,
                MAX(CASE WHEN cgt.locale = 'en' THEN cgt.main_effect END)            AS main_effect_en,
                MAX(CASE WHEN cgt.locale = 'fr' THEN cgt.echo_effect END)            AS echo_effect_fr,
                MAX(CASE WHEN cgt.locale = 'en' THEN cgt.echo_effect END)            AS echo_effect_en,
                COALESCE(
                    json_agg(DISTINCT cst.reference) FILTER (WHERE cst.reference IS NOT NULL),
                    '[]'
                )                                                                     AS sub_types
            FROM card c
            LEFT JOIN card_group               cg   ON cg.id  = c.card_group_id
            LEFT JOIN card_set                 cs   ON cs.id  = c.set_id
            LEFT JOIN faction                  f    ON f.id   = cg.faction_id
            LEFT JOIN rarity                   r    ON r.id   = cg.rarity_id
            LEFT JOIN card_type                ct   ON ct.id  = cg.card_type_id
            LEFT JOIN main_effect              me1  ON me1.id = cg.effect1_id
            LEFT JOIN main_effect              me2  ON me2.id = cg.effect2_id
            LEFT JOIN main_effect              me3  ON me3.id = cg.effect3_id
            LEFT JOIN main_effect              mee  ON mee.id = cg.echo_effect1_id
            LEFT JOIN card_search              cks  ON cks.card_id = c.id
            LEFT JOIN card_group_translation   cgt  ON cgt.card_group_id = cg.id
            LEFT JOIN card_group_sub_type_link cgsl ON cgsl.card_group_id = cg.id
            LEFT JOIN card_sub_type            cst  ON cst.id = cgsl.card_sub_type_id
            $where
            GROUP BY
                c.id, c.reference, c.kickstarter, c.promo, c.transfuge,
                c.is_serialized, c.variation, c.collector_number_formated_id, c.set_date,
                cs.reference, cs.date,
                cg.main_cost, cg.recall_cost, cg.ocean_power, cg.mountain_power, cg.forest_power,
                cg.is_banned, cg.is_suspended, cg.is_errated,
                f.code, r.reference, ct.reference,
                me1.ability_key, me2.ability_key, me3.ability_key, mee.ability_key,
                cks.has_effect, cks.keywords
            ORDER BY c.id
        SQL;
    }

    /** SQL expressions for the 12 slot fields — reused in full and partial queries. */
    private function effectSlotColumns(): string
    {
        $slots = [
            'slot1' => 'me1', 'slot2' => 'me2', 'slot3' => 'me3', 'echo' => 'mee',
        ];
        $parts = [];
        foreach ($slots as $name => $alias) {
            foreach (['trigger' => 1, 'condition' => 2, 'effect' => 3] as $part => $pos) {
                // Only process keys matching the numeric format \d+_\d+_\d+ —
                // old-format keys (e.g. "ALT_...") are skipped and return NULL.
                $parts[] = "CASE WHEN {$alias}.ability_key ~ '^\d+_\d+_\d+$'"
                    . " THEN NULLIF(SPLIT_PART({$alias}.ability_key, '_', {$pos})::integer, 0)"
                    . " ELSE NULL END AS {$name}_{$part}";
            }
        }
        return implode(",\n                ", $parts);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'reference'      => $row['reference'],
            'name_fr'        => $this->sanitize($row['name_fr']),
            'name_en'        => $this->sanitize($row['name_en']),
            'main_effect_fr' => $this->sanitize($row['main_effect_fr']),
            'main_effect_en' => $this->sanitize($row['main_effect_en']),
            'echo_effect_fr' => $this->sanitize($row['echo_effect_fr']),
            'echo_effect_en' => $this->sanitize($row['echo_effect_en']),
            'faction_code'   => $row['faction_code'],
            'set_reference'  => $row['set_reference'],
            'rarity'         => $row['rarity'],
            'card_type'      => $row['card_type'],
            'sub_types'      => json_decode((string) $row['sub_types'], true),
            'main_cost'      => $row['main_cost'] !== null ? (int) $row['main_cost'] : null,
            'recall_cost'    => $row['recall_cost'] !== null ? (int) $row['recall_cost'] : null,
            'ocean_power'    => $row['ocean_power'] !== null ? (int) $row['ocean_power'] : null,
            'mountain_power' => $row['mountain_power'] !== null ? (int) $row['mountain_power'] : null,
            'forest_power'   => $row['forest_power'] !== null ? (int) $row['forest_power'] : null,
            'is_banned'      => (bool) $row['is_banned'],
            'is_suspended'   => (bool) $row['is_suspended'],
            'is_errated'     => (bool) $row['is_errated'],
            'is_serialized'  => (bool) $row['is_serialized'],
            'kickstarter'    => (bool) $row['kickstarter'],
            'promo'          => (bool) $row['promo'],
            'transfuge'      => (bool) $row['transfuge'],
            'variation'                    => $row['variation'],
            'collector_number_formated_id' => $row['collector_number_formated_id'],
            'set_date'                     => $row['set_date'],
        ] + $this->hydrateEffectFields($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrateEffectFields(array $row): array
    {
        $t1 = isset($row['slot1_trigger'])  ? ($row['slot1_trigger']  !== null ? (int) $row['slot1_trigger']  : null) : null;
        $t2 = isset($row['slot2_trigger'])  ? ($row['slot2_trigger']  !== null ? (int) $row['slot2_trigger']  : null) : null;
        $t3 = isset($row['slot3_trigger'])  ? ($row['slot3_trigger']  !== null ? (int) $row['slot3_trigger']  : null) : null;
        $te = isset($row['echo_trigger'])   ? ($row['echo_trigger']   !== null ? (int) $row['echo_trigger']   : null) : null;

        return [
            'slot1_trigger'   => $t1,
            'slot1_condition' => isset($row['slot1_condition']) && $row['slot1_condition'] !== null ? (int) $row['slot1_condition'] : null,
            'slot1_effect'    => isset($row['slot1_effect'])    && $row['slot1_effect']    !== null ? (int) $row['slot1_effect']    : null,
            'slot2_trigger'   => $t2,
            'slot2_condition' => isset($row['slot2_condition']) && $row['slot2_condition'] !== null ? (int) $row['slot2_condition'] : null,
            'slot2_effect'    => isset($row['slot2_effect'])    && $row['slot2_effect']    !== null ? (int) $row['slot2_effect']    : null,
            'slot3_trigger'   => $t3,
            'slot3_condition' => isset($row['slot3_condition']) && $row['slot3_condition'] !== null ? (int) $row['slot3_condition'] : null,
            'slot3_effect'    => isset($row['slot3_effect'])    && $row['slot3_effect']    !== null ? (int) $row['slot3_effect']    : null,
            'echo_trigger'    => $te,
            'echo_condition'  => isset($row['echo_condition'])  && $row['echo_condition']  !== null ? (int) $row['echo_condition']  : null,
            'echo_effect'     => isset($row['echo_effect'])     && $row['echo_effect']     !== null ? (int) $row['echo_effect']     : null,
            'trigger_repeat_count' => $this->computeTriggerRepeatCount($t1, $t2, $t3, $te),
            'has_effect'  => isset($row['has_effect']) ? (bool) $row['has_effect'] : false,
            'keywords'    => isset($row['keywords'])   ? $this->parseKeywords($row['keywords']) : [],
            'transfuge'   => isset($row['transfuge'])  ? (bool) $row['transfuge'] : false,
        ];
    }

    private function computeCostRelation(mixed $main, mixed $recall): ?string
    {
        if ($main === null || $recall === null) {
            return null;
        }
        $m = (int) $main;
        $r = (int) $recall;
        if ($m === $r) return 'equal';
        if ($m > $r)  return 'mainHigher';
        return 'recallHigher';
    }

    private function computeTriggerRepeatCount(?int $t1, ?int $t2, ?int $t3, ?int $te): int
    {
        $counts = [];
        foreach ([$t1, $t2, $t3, $te] as $t) {
            if ($t !== null) {
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
        }
        return !empty($counts) ? max($counts) : 0;
    }

    /** Parse PostgreSQL TEXT[] string e.g. "{CORIACE,FUGACE}" into a PHP array. */
    private function parseKeywords(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '{}' || $raw === '') {
            return [];
        }
        return array_values(array_filter(explode(',', trim($raw, '{}'))));
    }
}
