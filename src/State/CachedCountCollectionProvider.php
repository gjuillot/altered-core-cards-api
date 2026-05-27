<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Card;
use App\Entity\CardGroup;
use App\Service\FilterCacheKeyService;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Decorator around CollectionProvider that avoids slow COUNT queries:
 *
 * - No filters: reads pg_class.reltuples — PostgreSQL's own row estimate,
 *   maintained by autovacuum ANALYZE. Near-instant (<1 ms), no table scan.
 *
 * - With filters: runs COUNT once and caches the result indefinitely in Redis,
 *   keyed by resource class + normalized filters (sorted, order-stable).
 *
 * - Deep page protection: checks the count BEFORE executing the OFFSET query.
 *   If the requested page exceeds the last page, returns an EmptyPaginator
 *   without hitting the database. Falls back to ABSOLUTE_MAX_PAGE for
 *   filtered queries whose count is not yet cached.
 */
final class CachedCountCollectionProvider implements ProviderInterface
{
    /** Query parameters that affect pagination offsets but not the total count. */
    private const PAGINATION_PARAMS = ['page', 'itemsPerPage', 'pagination', 'order'];

    private const TABLE_MAP = [
        Card::class      => 'card',
        CardGroup::class => 'card_group',
    ];

    /** Hard cap when no pre-total is available (e.g. first filtered request). */
    private const ABSOLUTE_MAX_PAGE = 5000;

    /** Hard cap on OFFSET regardless of cached total — prevents multi-million-row scans. */
    private const ABSOLUTE_MAX_OFFSET = 6_000_000;

    public function __construct(
        private readonly CollectionProvider $inner,
        #[Autowire(service: 'cache.card_counts')]
        private readonly CacheInterface $cache,
        #[Autowire(service: 'cache.card_counts')]
        private readonly CacheItemPoolInterface $cachePool,
        private readonly Connection $connection,
        private readonly FilterCacheKeyService $cacheKeyService,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        // SearchAwareCollectionProvider already applied Meilisearch pagination — no deep-offset risk.
        if (isset($context['_meili_total'])) {
            $result = $this->inner->provide($operation, $uriVariables, $context);
            if (!$result instanceof PaginatorInterface) {
                return $result;
            }
            return new CachedTotalPaginator($result, (float) $context['_meili_total']);
        }

        $filters      = $this->normalizeFilters($context['filters'] ?? []);
        $currentPage  = max(1, (int) ($context['filters']['page'] ?? 1));
        $itemsPerPage = max(1, (int) ($context['filters']['itemsPerPage'] ?? 30));

        // Pre-check: resolve total BEFORE running the OFFSET query to avoid scanning millions of rows.
        $preTotal = $this->tryGetPreTotal($operation, $filters);

        $offset = ($currentPage - 1) * $itemsPerPage;

        if ($offset >= self::ABSOLUTE_MAX_OFFSET) {
            return new EmptyPaginator((float) $currentPage, (float) $itemsPerPage, $preTotal ?? 0.0);
        }

        if ($preTotal !== null) {
            $maxPage = max(1, (int) ceil($preTotal / $itemsPerPage));
            if ($currentPage > $maxPage) {
                return new EmptyPaginator((float) $currentPage, (float) $itemsPerPage, $preTotal);
            }
        } elseif ($currentPage > self::ABSOLUTE_MAX_PAGE) {
            return new EmptyPaginator((float) $currentPage, (float) $itemsPerPage, 0.0);
        }

        $result = $this->inner->provide($operation, $uriVariables, $context);

        if (!$result instanceof PaginatorInterface) {
            return $result;
        }

        // Name (text) search: Meilisearch was unavailable, fall back to a one-off Doctrine COUNT.
        if (isset($filters['name'])) {
            return new CachedTotalPaginator($result, $result->getTotalItems());
        }

        // No filters → use PostgreSQL's own row estimate from the system catalog.
        // Updated by autovacuum after each ANALYZE; run `ANALYZE card` after bulk imports.
        if (empty($filters)) {
            $table = self::TABLE_MAP[$operation->getClass() ?? ''] ?? null;

            if ($table !== null) {
                $estimate = (int) $this->connection->fetchOne(
                    "SELECT reltuples::bigint FROM pg_class WHERE relname = ?",
                    [$table]
                );

                if ($estimate > 0) {
                    return new CachedTotalPaginator($result, (float) $estimate);
                }
            }
        }

        // Filtered queries → cache the COUNT result indefinitely in Redis.
        $cacheKey = $this->cacheKeyService->make($operation->getClass() ?? '', $filters);

        $totalItems = $this->cache->get($cacheKey, function (ItemInterface $item) use ($result): float {
            $item->expiresAfter(null);
            return $result->getTotalItems();
        });

        return new CachedTotalPaginator($result, (float) $totalItems);
    }

    /**
     * Tries to resolve the total item count before the OFFSET query runs.
     * Returns null when the count cannot be determined cheaply (e.g. filtered + not cached).
     */
    private function tryGetPreTotal(Operation $operation, array $filters): ?float
    {
        // No filters → pg_class estimate (sub-millisecond, no table scan).
        if (empty($filters)) {
            $table = self::TABLE_MAP[$operation->getClass() ?? ''] ?? null;

            if ($table !== null) {
                $estimate = (int) $this->connection->fetchOne(
                    "SELECT reltuples::bigint FROM pg_class WHERE relname = ?",
                    [$table]
                );

                if ($estimate > 0) {
                    return (float) $estimate;
                }
            }

            return null;
        }

        // Name search → count not in cache (too many combinations); fall back to hard cap.
        if (isset($filters['name'])) {
            return null;
        }

        // Filtered → peek the cache without triggering computation.
        $cacheKey = $this->cacheKeyService->make($operation->getClass() ?? '', $filters);
        $item = $this->cachePool->getItem($cacheKey);

        return $item->isHit() ? (float) $item->get() : null;
    }

    /**
     * Strips pagination params and sorts the filter array recursively so that
     * ?faction[]=AX&faction[]=LY and ?faction[]=LY&faction[]=AX produce the same key.
     */
    private function normalizeFilters(array $filters): array
    {
        foreach (self::PAGINATION_PARAMS as $param) {
            unset($filters[$param]);
        }

        $this->recursiveNormalize($filters);

        return $filters;
    }

    private function recursiveNormalize(array &$arr): void
    {
        ksort($arr);

        foreach ($arr as &$value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                sort($value); // multi-value filter: normalize order of values
            } else {
                $this->recursiveNormalize($value);
            }
        }
    }
}
