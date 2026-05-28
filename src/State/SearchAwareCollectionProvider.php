<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\FilteredCardCountRepository;
use App\Service\FilterCacheKeyService;
use App\Service\MeilisearchService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps CardCollectionProvider and resolves the total item count via Meilisearch
 * when a name (full-text) search is active.
 *
 * Meilisearch is called with limit=0, which returns estimatedTotalHits instantly
 * without fetching any documents. The total is injected into the context under
 * '_meili_total' so CachedCountCollectionProvider can use it directly and skip
 * the expensive Doctrine COUNT query.
 *
 * Meilisearch filter conditions are built from the active API Platform filters
 * so the total is accurate even when combined with structured filters (faction,
 * rarity, set, etc.).
 *
 * If Meilisearch is unavailable, the key is absent from the context and
 * CachedCountCollectionProvider falls back to a normal (uncached) Doctrine COUNT.
 */
final class SearchAwareCollectionProvider implements ProviderInterface
{
    /** API Platform filter key → Meilisearch filterable attribute */
    private const FILTER_MAP = [
        'faction.code'  => 'faction_code',
        'set.reference' => 'set_reference',
        'rarity'        => 'rarity',
        'cardType'      => 'card_type',
        'subTypes'      => 'sub_types',
        'mainCost'      => 'main_cost',
        'recallCost'    => 'recall_cost',
        'isSerialized'  => 'is_serialized',
        'promo'         => 'promo',
        'kickstarter'   => 'kickstarter',
        'isBanned'      => 'is_banned',
        'isSuspended'   => 'is_suspended',
        'isErrated'     => 'is_errated',
        'variation'     => 'variation',
    ];

    /** API Platform order key → Meilisearch sortable attribute */
    private const ORDER_MAP = [
        'set.date'                   => 'set_date',
        'setDate'                    => 'set_date',
        'collectorNumberFormatedId'  => 'collector_number_formated_id',
        'mainCost'                   => 'main_cost',
        'recallCost'                 => 'recall_cost',
    ];

    public function __construct(
        private readonly CardCollectionProvider $inner,
        private readonly MeilisearchService $meilisearch,
        private readonly FilterCacheKeyService $cacheKeyService,
        #[Autowire(service: 'cache.card_counts')]
        private readonly CacheItemPoolInterface $cachePool,
        private readonly FilteredCardCountRepository $filteredCountRepo,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $filters      = $context['filters'] ?? [];
        $nameQuery    = $this->extractNameQuery($filters);
        $meiliFilter  = $this->buildFilter($filters);
        $hasFilters   = $meiliFilter !== null;

        // Only go through Meilisearch when there's something to filter or search.
        if ($nameQuery === null && !$hasFilters) {
            return $this->inner->provide($operation, $uriVariables, $context);
        }

        $attrs        = $this->buildAttributesToSearchOn($filters);
        $sort         = $this->buildSort($filters);
        $page         = max(1, (int) ($filters['page'] ?? 1));
        $itemsPerPage = min(max(1, (int) ($filters['itemsPerPage'] ?? 30)), 1000);
        $offset       = ($page - 1) * $itemsPerPage;
        $query        = $nameQuery ?? '';

        $ids = $this->fetchIds($query, $meiliFilter, $attrs, $itemsPerPage, $offset, $sort);
        if ($ids !== null) {
            $context['_meili_ids']      = $ids;
            $context['filters']['page'] = 1; // Meilisearch already paginated; Doctrine fetches at OFFSET 0

            // Accurate total: Redis-cached DB count for structural filters.
            // Name-search queries use Meilisearch estimate (too varied to cache per-combination).
            if ($nameQuery === null) {
                $cacheKey  = $this->cacheKeyService->make($operation->getClass() ?? '', $filters);
                $cacheItem = $this->cachePool->getItem($cacheKey);
                if ($cacheItem->isHit()) {
                    $context['_meili_total'] = (float) $cacheItem->get();
                } else {
                    try {
                        // Slow on first request for this filter combination, cached indefinitely after.
                        $count = $this->filteredCountRepo->count($filters);
                        $cacheItem->set((float) $count)->expiresAfter(null);
                        $this->cachePool->save($cacheItem);
                        $context['_meili_total'] = (float) $count;
                    } catch (\Throwable) {
                        $total = $this->fetchTotal($query, $meiliFilter, $attrs);
                        if ($total !== null) {
                            $context['_meili_total'] = $total;
                        }
                    }
                }
            } else {
                $total = $this->fetchTotal($query, $meiliFilter, $attrs);
                if ($total !== null) {
                    $context['_meili_total'] = $total;
                }
            }

            // Strip filters that Meilisearch already handled so Doctrine doesn't
            // generate unnecessary JOINs (card_group → faction → card_type etc.).
            // Keep order[...] so the OrderFilter still applies the ORDER BY.
            foreach (array_keys(self::FILTER_MAP) as $filterKey) {
                unset($context['filters'][$filterKey]);
            }
            unset($context['filters']['name']);
        }

        return $this->inner->provide($operation, $uriVariables, $context);
    }

    private function extractNameQuery(array $filters): ?string
    {
        $name = $filters['name'] ?? null;

        if ($name === null || $name === '' || $name === []) {
            return null;
        }

        if (is_array($name)) {
            $parts = array_filter(array_map('trim', array_values($name)));
            return !empty($parts) ? implode(' ', $parts) : null;
        }

        $trimmed = trim((string) $name);
        return $trimmed !== '' ? $trimmed : null;
    }

    /** Returns null when Meilisearch is unavailable (triggers LIKE fallback in CardNameFilter). */
    private function fetchIds(string $query, ?string $filter, array $attributesToSearchOn, int $limit = 30, int $offset = 0, array $sort = []): ?array
    {
        try {
            return $this->meilisearch->searchIds($query, $attributesToSearchOn, $filter, $limit, $offset, $sort);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Returns null when Meilisearch is unavailable (triggers Doctrine COUNT fallback). */
    private function fetchTotal(string $query, ?string $filter, array $attributesToSearchOn = []): ?int
    {
        try {
            $params = ['limit' => 0];

            if ($filter !== null) {
                $params['filter'] = $filter;
            }

            if (!empty($attributesToSearchOn)) {
                $params['attributesToSearchOn'] = $attributesToSearchOn;
            }

            return $this->meilisearch->getIndex()->search($query ?: null, $params)->getEstimatedTotalHits() ?? 0;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Map API Platform order[...] params to Meilisearch sort syntax.
     * e.g. order[set.date]=desc → ['set_date:desc']
     *
     * @return string[]
     */
    private function buildSort(array $filters): array
    {
        $order = $filters['order'] ?? [];
        if (!is_array($order)) {
            return [];
        }

        $sort = [];
        foreach ($order as $field => $direction) {
            $meiliField = self::ORDER_MAP[$field] ?? null;
            if ($meiliField === null) {
                continue;
            }
            $dir    = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
            $sort[] = "$meiliField:$dir";
        }

        return $sort;
    }

    /**
     * Build the attributesToSearchOn list matching what CardNameFilter will pass to Meilisearch.
     * When name[fr]=foo, search only French fields; name[en]=foo → English only; name=foo → all.
     *
     * @return string[]
     */
    private function buildAttributesToSearchOn(array $filters): array
    {
        $name = $filters['name'] ?? null;

        if (!is_array($name)) {
            return [];
        }

        $attrs = [];
        foreach (array_keys($name) as $locale) {
            $attrs[] = "name_{$locale}";
            $attrs[] = "main_effect_{$locale}";
            $attrs[] = "echo_effect_{$locale}";
        }

        return $attrs;
    }

    private function buildFilter(array $filters): ?string
    {
        $parts = [];

        foreach ($filters as $key => $value) {
            if ($key === 'name') {
                continue;
            }

            $field = self::FILTER_MAP[$key] ?? null;

            if ($field === null || $value === null || $value === '') {
                continue;
            }

            // Range operators: mainCost[lte]=5 arrives as ['lte' => '5']
            if (is_array($value) && !array_is_list($value)) {
                static $rangeOps = ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];
                foreach ($value as $op => $val) {
                    $mOp = $rangeOps[$op] ?? null;
                    if ($mOp !== null && $val !== '' && $val !== null) {
                        $parts[] = sprintf('%s %s %s', $field, $mOp, $this->meiliValue($field, (string) $val));
                    }
                }
                continue;
            }

            $values = array_values(array_filter((array) $value, fn($v) => $v !== '' && $v !== null));

            if (empty($values)) {
                continue;
            }

            if (count($values) === 1) {
                $parts[] = sprintf('%s = %s', $field, $this->meiliValue($field, (string) $values[0]));
            } else {
                $quoted  = array_map(fn($v) => $this->meiliValue($field, (string) $v), $values);
                $parts[] = sprintf('%s IN [%s]', $field, implode(', ', $quoted));
            }
        }

        return !empty($parts) ? implode(' AND ', $parts) : null;
    }

    private function meiliValue(string $field, string $value): string
    {
        static $numeric = ['main_cost', 'recall_cost', 'ocean_power', 'mountain_power', 'forest_power'];
        static $bool    = ['is_serialized', 'is_banned', 'is_suspended', 'is_errated', 'promo', 'kickstarter'];

        if (in_array($field, $numeric, true) && is_numeric($value)) {
            return $value;
        }
        if (in_array($field, $bool, true)) {
            return in_array(strtolower($value), ['true', '1'], true) ? 'true' : 'false';
        }
        return sprintf('"%s"', addslashes($value));
    }
}
