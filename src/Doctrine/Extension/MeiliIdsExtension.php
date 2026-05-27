<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * When SearchAwareCollectionProvider resolved IDs via Meilisearch, inject
 * WHERE id IN (...) so Doctrine fetches only those rows — no filter JOINs needed.
 */
#[AutoconfigureTag('api_platform.doctrine.orm.query_extension.collection', ['priority' => 10])]
final class MeiliIdsExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        ?string $resourceClass = null,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!isset($context['_meili_ids'])) {
            return;
        }

        $ids  = $context['_meili_ids'];
        $root = $queryBuilder->getRootAliases()[0];

        if (empty($ids)) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        $p = $queryNameGenerator->generateParameterName('meili_ids');
        $queryBuilder
            ->andWhere("$root.id IN (:$p)")
            ->setParameter($p, $ids);
    }
}
