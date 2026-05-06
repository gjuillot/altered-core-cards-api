<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;

final class StatsService
{
    public const CACHE_KEY_PAGE   = 'stats.page_data';
    public const CACHE_KEY_EFFECTS = 'effects.stats_page_data';

    public function __construct(
        private readonly Connection            $connection,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public function getPageData(): ?array
    {
        $item = $this->cache->getItem(self::CACHE_KEY_PAGE);
        return $item->isHit() ? $item->get() : null;
    }

    public function buildAndCache(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                cs.name        AS set_name,
                cs.reference   AS set_ref,
                cs.date        AS set_date,
                f.name         AS faction_name,
                f.code         AS faction_code,
                f.position     AS faction_position,
                r.reference    AS rarity_ref,
                r.name_en      AS rarity_name,
                r.position     AS rarity_position,
                COUNT(c.id)    AS nb
             FROM card_group cg
             JOIN card c       ON c.card_group_id = cg.id
             JOIN card_set cs  ON c.set_id        = cs.id
             JOIN faction f    ON cg.faction_id   = f.id
             JOIN rarity r     ON cg.rarity_id    = r.id
             GROUP BY cs.id, cs.name, cs.reference, cs.date,
                      f.id, f.name, f.code, f.position,
                      r.id, r.reference, r.name_en, r.position
             ORDER BY cs.date DESC NULLS LAST, cs.name,
                      f.position,
                      CASE r.reference WHEN \'COMMON\' THEN 1 WHEN \'RARE\' THEN 2 WHEN \'EXALTED\' THEN 3 WHEN \'UNIQUE\' THEN 4 ELSE 5 END'
        );

        $globalRarityRows = $this->connection->fetchAllAssociative(
            'SELECT r.reference AS rarity_ref, r.name_en AS rarity_name, COUNT(c.id) AS nb
             FROM card_group cg
             JOIN card c   ON c.card_group_id = cg.id
             JOIN rarity r ON cg.rarity_id    = r.id
             GROUP BY r.id, r.reference, r.name_en
             ORDER BY CASE r.reference WHEN \'COMMON\' THEN 1 WHEN \'RARE\' THEN 2 WHEN \'EXALTED\' THEN 3 WHEN \'UNIQUE\' THEN 4 ELSE 5 END'
        );

        $globalRarities = [];
        foreach ($globalRarityRows as $r) {
            $globalRarities[$r['rarity_ref']] = [
                'ref'  => $r['rarity_ref'],
                'name' => $r['rarity_name'] ?? $r['rarity_ref'],
                'nb'   => (int) $r['nb'],
            ];
        }

        $sets = [];
        foreach ($rows as $row) {
            $setRef      = $row['set_ref'];
            $factionCode = $row['faction_code'];

            if (!isset($sets[$setRef])) {
                $sets[$setRef] = ['name' => $row['set_name'], 'ref' => $setRef, 'total' => 0, 'factions' => []];
            }
            if (!isset($sets[$setRef]['factions'][$factionCode])) {
                $sets[$setRef]['factions'][$factionCode] = [
                    'name' => $row['faction_name'], 'code' => $factionCode, 'total' => 0, 'rarities' => [],
                ];
            }

            $nb = (int) $row['nb'];
            $sets[$setRef]['factions'][$factionCode]['rarities'][] = [
                'ref'  => $row['rarity_ref'],
                'name' => $row['rarity_name'] ?? $row['rarity_ref'],
                'nb'   => $nb,
            ];
            $sets[$setRef]['factions'][$factionCode]['total'] += $nb;
            $sets[$setRef]['total'] += $nb;
        }

        $data = ['sets' => $sets, 'globalRarities' => $globalRarities];

        $item = $this->cache->getItem(self::CACHE_KEY_PAGE);
        $item->set($data);
        $item->expiresAfter(null); // never auto-expire — refreshed by warmup command
        $this->cache->save($item);

        return $data;
    }
}
