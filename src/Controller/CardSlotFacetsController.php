<?php

namespace App\Controller;

use App\Repository\SlotFacetRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Slot-composition cascade facets for AND-mode filter building.
 *
 * Unlike /api/cards/facets (which returns cross-slot co-occurrences for OR mode),
 * this endpoint returns only values that appear in the SAME slot as the selected
 * components — enabling a trigger → condition → effect cascade.
 *
 * Usage:
 *   ?slot[trigger]=2                          → compatible conditions + effects for trigger 2
 *   ?slot[trigger]=2&slot[condition]=30       → compatible effects for trigger 2 + condition 30
 *   ?slot[condition]=30                       → compatible triggers + effects for condition 30
 *   ?slot[effect]=45                          → compatible triggers + conditions for effect 45
 */
final class CardSlotFacetsController extends AbstractController
{
    private const TTL = 86400; // 24 h

    public function __construct(
        private readonly SlotFacetRepository $repository,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[Route('/api/cards/slot-facets', name: 'api_cards_slot_facets', methods: ['GET'], priority: 1)]
    public function __invoke(Request $request): JsonResponse
    {
        $slot = $request->query->all('slot');

        $triggerAlteredId   = isset($slot['trigger'])   && $slot['trigger']   !== '' ? (int) $slot['trigger']   : null;
        $conditionAlteredId = isset($slot['condition'])  && $slot['condition']  !== '' ? (int) $slot['condition']  : null;
        $effectAlteredId    = isset($slot['effect'])     && $slot['effect']     !== '' ? (int) $slot['effect']     : null;

        if ($triggerAlteredId === null && $conditionAlteredId === null && $effectAlteredId === null) {
            return $this->json(['error' => 'Provide at least slot[trigger], slot[condition] or slot[effect]'], 422);
        }

        $cacheKey = 'slot_facets'
            . ($triggerAlteredId   !== null ? ".t{$triggerAlteredId}"   : '')
            . ($conditionAlteredId !== null ? ".c{$conditionAlteredId}" : '')
            . ($effectAlteredId    !== null ? ".e{$effectAlteredId}"    : '');

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $this->json($item->get());
        }

        $triggers   = [];
        $conditions = [];
        $effects    = [];

        if ($triggerAlteredId !== null) {
            $conditions = $this->repository->conditionsForTrigger($triggerAlteredId);
            $effects    = $this->repository->effectsForTrigger($triggerAlteredId, $conditionAlteredId);
        }

        if ($conditionAlteredId !== null && $triggerAlteredId === null) {
            $triggers = $this->repository->triggersForCondition($conditionAlteredId);
            $effects  = $this->repository->effectsForCondition($conditionAlteredId);
        }

        if ($effectAlteredId !== null && $triggerAlteredId === null && $conditionAlteredId === null) {
            $triggers   = $this->repository->triggersForEffect($effectAlteredId);
            $conditions = $this->repository->conditionsForEffect($effectAlteredId);
        }

        $result = ['triggers' => $triggers, 'conditions' => $conditions, 'effects' => $effects];

        $item->set($result)->expiresAfter(self::TTL);
        $this->cache->save($item);

        return $this->json($result);
    }
}
