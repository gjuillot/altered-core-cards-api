<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Builds raw card documents for search indexing.
 * Single source of truth for the DBAL query — no SQL elsewhere.
 */
final class CardDocumentRepository
{
    public function __construct(private readonly Connection $connection) {}

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
        // Round-trip through json_encode/decode: JSON_INVALID_UTF8_IGNORE drops any
        // byte sequence that is not valid UTF-8, guaranteeing the result can be
        // re-encoded by the Meilisearch SDK without producing "trailing characters".
        $encoded = json_encode($text, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        $clean = json_decode($encoded);
        if (!is_string($clean)) {
            return null;
        }
        // Strip ASCII control characters that are illegal in JSON strings
        // (keep \t=0x09, \n=0x0A, \r=0x0D). No /u flag needed — these are
        // single-byte values that never appear in UTF-8 multibyte sequences.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
    }

    private function buildSql(bool $whereCardId = false): string
    {
        $where = $whereCardId ? 'WHERE c.id = :id' : '';

        return <<<SQL
            SELECT
                c.id,
                c.reference,
                c.kickstarter,
                c.promo,
                c.is_serialized,
                c.variation,
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
            LEFT JOIN card_group              cg   ON cg.id  = c.card_group_id
            LEFT JOIN card_set                cs   ON cs.id  = c.set_id
            LEFT JOIN faction                 f    ON f.id   = cg.faction_id
            LEFT JOIN rarity                  r    ON r.id   = cg.rarity_id
            LEFT JOIN card_type               ct   ON ct.id  = cg.card_type_id
            LEFT JOIN card_group_translation  cgt  ON cgt.card_group_id = cg.id
            LEFT JOIN card_group_sub_type_link cgsl ON cgsl.card_group_id = cg.id
            LEFT JOIN card_sub_type           cst  ON cst.id = cgsl.card_sub_type_id
            $where
            GROUP BY
                c.id, c.reference, c.kickstarter, c.promo, c.is_serialized, c.variation,
                cs.reference,
                cg.main_cost, cg.recall_cost, cg.ocean_power, cg.mountain_power, cg.forest_power,
                cg.is_banned, cg.is_suspended, cg.is_errated,
                f.code, r.reference, ct.reference
            ORDER BY c.id
        SQL;
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
            'variation'      => $row['variation'],
        ];
    }
}
