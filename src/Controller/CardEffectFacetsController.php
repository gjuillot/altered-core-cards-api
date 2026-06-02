<?php

namespace App\Controller;

use App\Repository\SlotFacetRepository;
use App\Service\MeilisearchFilterBuilderService;
use App\Service\MeilisearchService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Filter-aware slot composition facets for AND-mode filter building.
 *
 * Takes the same filter params as GET /api/cards, fetches matching card IDs
 * from Meilisearch, then returns every distinct T+C+E slot composition
 * present across those cards with its card count.
 *
 * Usage:
 *   GET /api/cards/effect-facets?rarity[]=UNIQUE&variation[]=standard
 *
 * Response:
 *   [
 *     {"trigger": 22, "condition": 182, "effect": 125, "count": 50},
 *     {"trigger": 22, "condition": 182, "effect": null, "count": 12},
 *     ...
 *   ]
 */
final class CardEffectFacetsController extends AbstractController
{
    private const MAX_IDS = 50000;
    private const TTL     = 3600; // 1 h

    public function __construct(
        private readonly MeilisearchService $meilisearch,
        private readonly MeilisearchFilterBuilderService $filterBuilder,
        private readonly SlotFacetRepository $repository,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[Route('/api/cards/effect-facets', name: 'api_cards_effect_facets', methods: ['GET'], priority: 1)]
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->query->all();

        if ($this->filterBuilder->hasUnmappedFilters($filters)) {
            return $this->json(['error' => 'Unsupported filter'], 422);
        }

        $filter   = $this->filterBuilder->buildFilter($filters);
        $cacheKey = 'effect_facets.' . md5($filter ?? '');

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $this->json($item->get());
        }

        try {
            $ids = $this->meilisearch->searchIds(limit: self::MAX_IDS, filter: $filter);
        } catch (\Throwable) {
            return $this->json([], 503);
        }

        $result = $this->repository->slotCompositionsForCards($ids);

        $item->set($result)->expiresAfter(self::TTL);
        $this->cache->save($item);

        return $this->json($result);
    }
}
