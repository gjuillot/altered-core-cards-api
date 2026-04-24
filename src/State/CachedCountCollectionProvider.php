<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Decorator around CollectionProvider that caches the COUNT query result
 * indefinitely in Redis, keyed by resource class + active filters (excluding
 * pagination params like page/itemsPerPage which don't affect the total).
 *
 * On first request (cache miss): runs COUNT once and stores it.
 * On subsequent requests: returns the cached total, no COUNT query.
 */
final class CachedCountCollectionProvider implements ProviderInterface
{
    /** Query parameters that affect pagination but not the total count. */
    private const PAGINATION_PARAMS = ['page', 'itemsPerPage', 'pagination'];

    public function __construct(
        private readonly CollectionProvider $inner,
        #[Autowire(service: 'cache.card_counts')]
        private readonly CacheInterface $cache,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->inner->provide($operation, $uriVariables, $context);

        if (!$result instanceof PaginatorInterface) {
            return $result;
        }

        $cacheKey = $this->buildCacheKey($operation, $context);

        $totalItems = $this->cache->get($cacheKey, function (ItemInterface $item) use ($result): float {
            $item->expiresAfter(null); // store indefinitely — data barely changes
            return $result->getTotalItems();
        });

        return new CachedTotalPaginator($result, (float) $totalItems);
    }

    private function buildCacheKey(Operation $operation, array $context): string
    {
        $filters = $context['filters'] ?? [];

        foreach (self::PAGINATION_PARAMS as $param) {
            unset($filters[$param]);
        }

        ksort($filters);

        return 'count_' . md5(($operation->getClass() ?? '') . serialize($filters));
    }
}
