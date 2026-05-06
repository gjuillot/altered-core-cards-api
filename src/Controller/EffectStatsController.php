<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EffectStatsController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[Route('/effects/stats', name: 'effects_stats')]
    public function index(): Response
    {
        $cached = $this->cache->getItem('effects.stats_page_data');

        if ($cached->isHit()) {
            ['sets' => $sets, 'topEffects' => $topEffects] = $cached->get();
            return $this->render('effects/stats.html.twig', compact('sets', 'topEffects'));
        }

        $sets       = $this->buildSetEffects();
        $topEffects = $this->buildTopEffects();

        $cached->set(compact('sets', 'topEffects'));
        $cached->expiresAfter(3600);
        $this->cache->save($cached);

        return $this->render('effects/stats.html.twig', compact('sets', 'topEffects'));
    }

    private function buildSetEffects(): array
    {
        $rows = $this->connection->fetchAllAssociative("
            WITH effect_cg AS (
                SELECT DISTINCT cg.id AS cg_id, cg.faction_id,
                                me.id AS me_id, me.text_fr, me.text_en, me.ability_key,
                                cs.reference AS set_ref, cs.name AS set_name, cs.date AS set_date
                FROM card_group cg
                JOIN card c         ON c.card_group_id = cg.id
                JOIN card_set cs    ON c.set_id = cs.id
                JOIN main_effect me ON me.id = ANY(ARRAY[cg.effect1_id, cg.effect2_id, cg.effect3_id])
                WHERE me.ability_key IS NOT NULL
            ),
            set_effect_total AS (
                SELECT set_ref, me_id, COUNT(DISTINCT cg_id) AS total
                FROM effect_cg
                GROUP BY set_ref, me_id
            )
            SELECT
                ec.set_ref, ec.set_name, ec.set_date,
                ec.me_id, ec.text_fr, ec.text_en, ec.ability_key,
                se.total,
                f.code     AS faction_code,
                f.position AS faction_pos,
                COUNT(DISTINCT ec.cg_id) AS faction_count
            FROM effect_cg ec
            JOIN set_effect_total se ON se.set_ref = ec.set_ref AND se.me_id = ec.me_id
            JOIN faction f           ON ec.faction_id = f.id
            GROUP BY ec.set_ref, ec.set_name, ec.set_date,
                     ec.me_id, ec.text_fr, ec.text_en, ec.ability_key,
                     se.total, f.id, f.code, f.position
            ORDER BY ec.set_date DESC NULLS LAST, se.total DESC, f.position
        ");

        $sets = [];
        foreach ($rows as $row) {
            $setRef  = $row['set_ref'];
            $meId    = $row['me_id'];

            if (!isset($sets[$setRef])) {
                $sets[$setRef] = ['name' => $row['set_name'], 'ref' => $setRef, 'effects' => []];
            }

            if (!isset($sets[$setRef]['effects'][$meId])) {
                $sets[$setRef]['effects'][$meId] = [
                    'id'          => $meId,
                    'text_fr'     => $row['text_fr'],
                    'text_en'     => $row['text_en'],
                    'ability_key' => $row['ability_key'],
                    'total'       => (int) $row['total'],
                    'factions'    => [],
                ];
            }

            $sets[$setRef]['effects'][$meId]['factions'][$row['faction_code']] = (int) $row['faction_count'];
        }

        // Re-index effects as plain arrays
        foreach ($sets as &$set) {
            $set['effects'] = array_values($set['effects']);
        }

        return $sets;
    }

    private function buildTopEffects(): array
    {
        $rows = $this->connection->fetchAllAssociative("
            WITH effect_cg AS (
                SELECT DISTINCT cg.id AS cg_id, cg.faction_id, me.id AS me_id,
                                me.text_fr, me.text_en, me.ability_key
                FROM card_group cg
                JOIN main_effect me ON me.id = ANY(ARRAY[cg.effect1_id, cg.effect2_id, cg.effect3_id])
                WHERE me.ability_key IS NOT NULL
            ),
            top_me AS (
                SELECT me_id, text_fr, text_en, ability_key, COUNT(DISTINCT cg_id) AS total
                FROM effect_cg
                GROUP BY me_id, text_fr, text_en, ability_key
                ORDER BY total DESC
                LIMIT 30
            )
            SELECT
                tm.me_id, tm.text_fr, tm.text_en, tm.ability_key, tm.total,
                f.code     AS faction_code,
                f.position AS faction_pos,
                COUNT(DISTINCT ec.cg_id) AS faction_count
            FROM top_me tm
            JOIN effect_cg ec ON ec.me_id = tm.me_id
            JOIN faction f    ON ec.faction_id = f.id
            GROUP BY tm.me_id, tm.text_fr, tm.text_en, tm.ability_key, tm.total, f.id, f.code, f.position
            ORDER BY tm.total DESC, f.position
        ");

        $effects = [];
        foreach ($rows as $row) {
            $id = $row['me_id'];
            if (!isset($effects[$id])) {
                $effects[$id] = [
                    'id'          => $id,
                    'text_fr'     => $row['text_fr'],
                    'text_en'     => $row['text_en'],
                    'ability_key' => $row['ability_key'],
                    'total'       => (int) $row['total'],
                    'factions'    => [],
                ];
            }
            $effects[$id]['factions'][$row['faction_code']] = (int) $row['faction_count'];
        }

        return array_values($effects);
    }
}
