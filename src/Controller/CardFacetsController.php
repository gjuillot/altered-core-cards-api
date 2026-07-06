<?php

namespace App\Controller;

use App\Service\MeilisearchFilterBuilderService;
use App\Service\MeilisearchService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CardFacetsController extends AbstractController
{
    private const TTL = 3600; // 1 h — filtre-dépendant, expiration plus courte que slot-facets

    public function __construct(
        private readonly MeilisearchService $meilisearch,
        private readonly MeilisearchFilterBuilderService $filterBuilder,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /**
     * Returns facet distributions for triggers, conditions and effects
     * matching the same filter params as GET /api/cards.
     *
     * Response:
     * {
     *   "triggers":   { "5": 42, "7": 18 },
     *   "conditions": { "166": 28 },
     *   "effects":    { "45": 20 }
     * }
     * Keys are alteredId values (as strings), values are document counts.
     */
    #[Route('/api/cards/facets', name: 'api_cards_facets', methods: ['GET'], priority: 1)]
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->query->all();

        if ($this->filterBuilder->hasUnmappedFilters($filters)) {
            return $this->json(['triggers' => [], 'conditions' => [], 'effects' => []], 422);
        }

        $filter   = $this->filterBuilder->buildFilter($filters);
        $cacheKey = 'card_facets.' . md5($filter ?? '');

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $this->json($item->get());
        }

        try {
            $facets = $this->meilisearch->getFacets($filter);
        } catch (\Throwable) {
            return $this->json(['triggers' => [], 'conditions' => [], 'effects' => []], 503);
        }

        $item->set($facets)->expiresAfter(self::TTL);
        $this->cache->save($item);

        return $this->json($facets);
    }
}
