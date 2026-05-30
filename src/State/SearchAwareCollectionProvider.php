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
 */
final class SearchAwareCollectionProvider implements ProviderInterface
{
    /** API Platform filter key → Meilisearch filterable attribute (simple 1:1 mapping) */
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
        'transfuge'     => 'transfuge',
        'isBanned'      => 'is_banned',
        'isSuspended'   => 'is_suspended',
        'isErrated'     => 'is_errated',
        'variation'     => 'variation',
    ];

    /** Filters handled by buildEffectFilters() — not in FILTER_MAP but still mappable */
    private const EFFECT_FILTER_KEYS = [
        'effectTriggerType', 'effectKeyword', 'effectKeywordMode',
        'effectSlot', 'effectSlotMode', 'hasNoEffect', 'minSameTriggerCount',
    ];

    /** API Platform order key → Meilisearch sortable attribute */
    private const ORDER_MAP = [
        'set.date'                  => 'set_date',
        'setDate'                   => 'set_date',
        'collectorNumberFormatedId' => 'collector_number_formated_id',
        'mainCost'                  => 'main_cost',
        'recallCost'                => 'recall_cost',
    ];

    private const SLOT_NAMES = ['slot1', 'slot2', 'slot3', 'echo'];

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
        $filters     = $context['filters'] ?? [];
        $nameQuery   = $this->extractNameQuery($filters);
        $meiliFilter = $this->buildFilter($filters);
        $hasFilters  = $meiliFilter !== null;

        if ($nameQuery === null && !$hasFilters) {
            return $this->inner->provide($operation, $uriVariables, $context);
        }

        if ($this->hasUnmappedFilters($filters)) {
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
            $context['filters']['page'] = 1;

            // Total: Meilisearch estimatedTotalHits for all cases.
            // ~0.2% inaccuracy is acceptable; avoids expensive DB COUNT on every first request.
            $total = $this->fetchTotal($query, $meiliFilter, $attrs);
            if ($total !== null) {
                $context['_meili_total'] = $total;
            }

            foreach (array_keys(self::FILTER_MAP) as $filterKey) {
                unset($context['filters'][$filterKey]);
            }
            foreach (self::EFFECT_FILTER_KEYS as $filterKey) {
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

    private function fetchIds(string $query, ?string $filter, array $attributesToSearchOn, int $limit = 30, int $offset = 0, array $sort = []): ?array
    {
        try {
            return $this->meilisearch->searchIds($query, $attributesToSearchOn, $filter, $limit, $offset, $sort);
        } catch (\Throwable) {
            return null;
        }
    }

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

    /** @return string[] */
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

    /** @return string[] */
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

    private function hasUnmappedFilters(array $filters): bool
    {
        static $skip = ['name', 'page', 'itemsPerPage', 'pagination', 'order'];
        foreach (array_keys($filters) as $key) {
            if (
                !isset(self::FILTER_MAP[$key])
                && !in_array($key, $skip, true)
                && !in_array($key, self::EFFECT_FILTER_KEYS, true)
            ) {
                return true;
            }
        }
        return false;
    }

    private function buildFilter(array $filters): ?string
    {
        $parts = [];

        // ── Simple 1:1 mapped filters ─────────────────────────────────────────
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

        // ── Effect filters ────────────────────────────────────────────────────
        $effectParts = $this->buildEffectFilters($filters);
        if ($effectParts !== null) {
            $parts[] = $effectParts;
        }

        return !empty($parts) ? implode(' AND ', $parts) : null;
    }

    private function buildEffectFilters(array $filters): ?string
    {
        $parts = [];

        // hasNoEffect=true → has_effect = false
        if (isset($filters['hasNoEffect']) && $filters['hasNoEffect'] !== '') {
            $noEffect = in_array(strtolower((string) $filters['hasNoEffect']), ['true', '1'], true);
            $parts[]  = 'has_effect = ' . ($noEffect ? 'false' : 'true');
        }

        // minSameTriggerCount=N → trigger_repeat_count >= N
        if (isset($filters['minSameTriggerCount']) && $filters['minSameTriggerCount'] !== '') {
            $n       = max(1, (int) $filters['minSameTriggerCount']);
            $parts[] = "trigger_repeat_count >= $n";
        }

        // effectTriggerType=alteredId → any slot_trigger = alteredId
        if (isset($filters['effectTriggerType']) && $filters['effectTriggerType'] !== '') {
            $ids = array_values(array_filter(array_map('intval', (array) $filters['effectTriggerType'])));
            if (!empty($ids)) {
                $parts[] = $this->buildTriggerFilter($ids);
            }
        }

        // effectKeyword / effectKeywordMode
        if (isset($filters['effectKeyword'])) {
            $kws  = array_values(array_filter((array) $filters['effectKeyword'], fn($v) => $v !== '' && $v !== null));
            $mode = strtolower((string) ($filters['effectKeywordMode'] ?? 'or'));
            if (!empty($kws)) {
                $kwParts = array_map(fn($kw) => sprintf('keywords = "%s"', addslashes((string) $kw)), $kws);
                $parts[] = '(' . implode($mode === 'and' ? ' AND ' : ' OR ', $kwParts) . ')';
            }
        }

        // effectSlot — full slot matching (trigger + condition + effect per slot)
        if (isset($filters['effectSlot']) && is_array($filters['effectSlot'])) {
            $slotFilter = $this->buildEffectSlotFilter(
                $filters['effectSlot'],
                (string) ($filters['effectSlotMode'] ?? 'or'),
            );
            if ($slotFilter !== null) {
                $parts[] = $slotFilter;
            }
        }

        return !empty($parts) ? implode(' AND ', $parts) : null;
    }

    /** "trigger X is present in any slot" */
    private function buildTriggerFilter(array $alteredIds): string
    {
        $conditions = [];
        foreach (self::SLOT_NAMES as $slot) {
            foreach ($alteredIds as $id) {
                $conditions[] = "{$slot}_trigger = $id";
            }
        }
        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * Build a Meilisearch filter for effectSlot[N][trigger/condition/effect].
     *
     * Each slot spec is matched against all 4 physical slots via OR.
     * Multiple specs are combined with AND or OR depending on effectSlotMode.
     */
    private function buildEffectSlotFilter(array $slots, string $mode): ?string
    {
        $specFilters = [];

        foreach ($slots as $slotDef) {
            if (!is_array($slotDef)) {
                continue;
            }

            $trigger   = isset($slotDef['trigger'])   && $slotDef['trigger']   !== '' ? (int) $slotDef['trigger']   : null;
            $condition = isset($slotDef['condition'])  && $slotDef['condition']  !== '' ? (int) $slotDef['condition']  : null;
            $effect    = isset($slotDef['effect'])     && $slotDef['effect']     !== '' ? (int) $slotDef['effect']     : null;

            if ($trigger === null && $condition === null && $effect === null) {
                continue;
            }

            // For each physical slot, build the AND condition
            $perSlot = [];
            foreach (self::SLOT_NAMES as $slotName) {
                $slotConds = [];
                if ($trigger   !== null) $slotConds[] = "{$slotName}_trigger = $trigger";
                if ($condition !== null) $slotConds[] = "{$slotName}_condition = $condition";
                if ($effect    !== null) $slotConds[] = "{$slotName}_effect = $effect";

                if (!empty($slotConds)) {
                    $perSlot[] = '(' . implode(' AND ', $slotConds) . ')';
                }
            }

            if (!empty($perSlot)) {
                $specFilters[] = '(' . implode(' OR ', $perSlot) . ')';
            }
        }

        if (empty($specFilters)) {
            return null;
        }

        $glue = strtolower($mode) === 'and' ? ' AND ' : ' OR ';
        return '(' . implode($glue, $specFilters) . ')';
    }

    private function meiliValue(string $field, string $value): string
    {
        static $numeric = ['main_cost', 'recall_cost', 'ocean_power', 'mountain_power', 'forest_power'];
        static $bool    = ['is_serialized', 'is_banned', 'is_suspended', 'is_errated', 'promo', 'kickstarter', 'transfuge'];

        if (in_array($field, $numeric, true) && is_numeric($value)) {
            return $value;
        }
        if (in_array($field, $bool, true)) {
            return in_array(strtolower($value), ['true', '1'], true) ? 'true' : 'false';
        }
        return sprintf('"%s"', addslashes($value));
    }
}
