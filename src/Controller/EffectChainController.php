<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Provides chained effect selection data for the query UI.
 * Each endpoint filters by any combination of the other two parts.
 * All responses are cached indefinitely in Redis.
 */
#[Route('/api/effect-chain')]
final class EffectChainController extends AbstractController
{
    public function __construct(
        private readonly Connection     $connection,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Triggers compatible with a given condition and/or effect.
     * GET /api/effect-chain/triggers?condition={alteredId}&effect={alteredId}
     */
    #[Route('/triggers', name: 'effect_chain_triggers', methods: ['GET'])]
    public function triggers(Request $request): JsonResponse
    {
        $condition = (int) $request->query->get('condition', 0);
        $effect    = (int) $request->query->get('effect', 0);

        $key  = "effect_chain.triggers.c{$condition}.e{$effect}";
        $data = $this->cache->get($key, function (ItemInterface $item) use ($condition, $effect): array {
            $item->expiresAfter(null);
            return $this->fetchPart('trigger', $condition, $effect);
        });

        return $this->json($data);
    }

    /**
     * Conditions compatible with a given trigger and/or effect.
     * GET /api/effect-chain/conditions?trigger={alteredId}&effect={alteredId}
     */
    #[Route('/conditions', name: 'effect_chain_conditions', methods: ['GET'])]
    public function conditions(Request $request): JsonResponse
    {
        $trigger = (int) $request->query->get('trigger', 0);
        $effect  = (int) $request->query->get('effect', 0);

        $key  = "effect_chain.conditions.t{$trigger}.e{$effect}";
        $data = $this->cache->get($key, function (ItemInterface $item) use ($trigger, $effect): array {
            $item->expiresAfter(null);
            return $this->fetchPart('condition', $trigger, $effect);
        });

        return $this->json($data);
    }

    /**
     * Effects compatible with a given trigger and/or condition.
     * GET /api/effect-chain/effects?trigger={alteredId}&condition={alteredId}
     */
    #[Route('/effects', name: 'effect_chain_effects', methods: ['GET'])]
    public function effects(Request $request): JsonResponse
    {
        $trigger   = (int) $request->query->get('trigger', 0);
        $condition = (int) $request->query->get('condition', 0);

        $key  = "effect_chain.effects.t{$trigger}.c{$condition}";
        $data = $this->cache->get($key, function (ItemInterface $item) use ($trigger, $condition): array {
            $item->expiresAfter(null);
            return $this->fetchPart('effect', $trigger, $condition);
        });

        return $this->json($data);
    }

    /**
     * Generic: fetch distinct rows for $target part, filtered by the other two (0 = no filter).
     *
     * @param 'trigger'|'condition'|'effect' $target
     */
    private function fetchPart(string $target, int $filter1, int $filter2): array
    {
        $tables = [
            'trigger'   => ['ability_trigger',   'ability_trigger_id'],
            'condition' => ['ability_condition',  'ability_condition_id'],
            'effect'    => ['ability_effect',     'ability_effect_id'],
        ];

        // The two "other" parts in order
        $others = array_values(array_filter(
            array_keys($tables),
            fn($k) => $k !== $target
        ));

        [$targetTable, $targetFk] = $tables[$target];
        [$fk1Name,     $fk1Col  ] = [$others[0], $tables[$others[0]][1]];
        [$fk2Name,     $fk2Col  ] = [$others[1], $tables[$others[1]][1]];

        $sql    = "SELECT DISTINCT t.altered_id AS id, t.text_fr AS fr, t.text_en AS en
                   FROM main_effect me
                   JOIN {$targetTable} t ON t.id = me.{$targetFk}";
        $params = [];

        if ($filter1 !== 0) {
            $sql .= " JOIN {$tables[$fk1Name][0]} f1 ON f1.id = me.{$fk1Col} AND f1.altered_id = :f1";
            $params['f1'] = $filter1;
        }
        if ($filter2 !== 0) {
            $sql .= " JOIN {$tables[$fk2Name][0]} f2 ON f2.id = me.{$fk2Col} AND f2.altered_id = :f2";
            $params['f2'] = $filter2;
        }

        $sql .= ' ORDER BY t.altered_id';

        return $this->connection->fetchAllAssociative($sql, $params);
    }
}
