<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\MeilisearchService;

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
    ];

    public function __construct(
        private readonly CardCollectionProvider $inner,
        private readonly MeilisearchService $meilisearch,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $filters   = $context['filters'] ?? [];
        $nameQuery = $this->extractNameQuery($filters);

        if ($nameQuery === null) {
            return $this->inner->provide($operation, $uriVariables, $context);
        }

        $meiliFilter  = $this->buildFilter($filters);
        $attrs        = $this->buildAttributesToSearchOn($filters);
        $page         = max(1, (int) ($filters['page'] ?? 1));
        $itemsPerPage = min(max(1, (int) ($filters['itemsPerPage'] ?? 30)), 1000);
        $offset       = ($page - 1) * $itemsPerPage;

        $total = $this->fetchTotal($nameQuery, $meiliFilter, $attrs);
        if ($total !== null) {
            $context['_meili_total'] = $total;
        }

        $ids = $this->fetchIds($nameQuery, $meiliFilter, $attrs, $itemsPerPage, $offset);
        if ($ids !== null) {
            $context['_meili_ids']      = $ids;
            $context['filters']['page'] = 1; // Meilisearch already applied pagination; Doctrine fetches at OFFSET 0
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
    private function fetchIds(string $query, ?string $filter, array $attributesToSearchOn, int $limit = 30, int $offset = 0): ?array
    {
        try {
            return $this->meilisearch->searchIds($query, $attributesToSearchOn, $filter, $limit, $offset);
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

            return $this->meilisearch->getIndex()->search($query, $params)->getEstimatedTotalHits() ?? 0;
        } catch (\Throwable) {
            return null;
        }
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

            $values = array_values(array_filter((array) $value, fn($v) => $v !== '' && $v !== null));

            if (empty($values)) {
                continue;
            }

            if (count($values) === 1) {
                $parts[] = sprintf('%s = "%s"', $field, addslashes((string) $values[0]));
            } else {
                $quoted  = array_map(fn($v) => sprintf('"%s"', addslashes((string) $v)), $values);
                $parts[] = sprintf('%s IN [%s]', $field, implode(', ', $quoted));
            }
        }

        return !empty($parts) ? implode(' AND ', $parts) : null;
    }
}
