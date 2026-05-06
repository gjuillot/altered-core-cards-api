<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Card;
use App\Entity\CardGroup;
use Doctrine\DBAL\Connection;
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
 */
final class CachedCountCollectionProvider implements ProviderInterface
{
    /** Query parameters that affect pagination offsets but not the total count. */
    private const PAGINATION_PARAMS = ['page', 'itemsPerPage', 'pagination'];

    private const TABLE_MAP = [
        Card::class      => 'card',
        CardGroup::class => 'card_group',
    ];

    public function __construct(
        private readonly CollectionProvider $inner,
        #[Autowire(service: 'cache.card_counts')]
        private readonly CacheInterface $cache,
        private readonly Connection $connection,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->inner->provide($operation, $uriVariables, $context);

        if (!$result instanceof PaginatorInterface) {
            return $result;
        }

        $filters = $this->normalizeFilters($context['filters'] ?? []);

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
        $cacheKey = 'count_' . md5(($operation->getClass() ?? '') . serialize($filters));

        $totalItems = $this->cache->get($cacheKey, function (ItemInterface $item) use ($result): float {
            $item->expiresAfter(null);
            return $result->getTotalItems();
        });

        return new CachedTotalPaginator($result, (float) $totalItems);
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
