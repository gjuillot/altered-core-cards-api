<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Debug\FilterProfiler;
use App\Entity\Card;
use App\Entity\CardGroup;
use App\Entity\CardGroupTranslation;
use App\Search\SearchBackendInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Name search with pluggable backend (Meilisearch, …) + PostgreSQL LIKE fallback.
 *
 * name=foo          → full-text across all locales
 * name[fr]=foo      → full-text on French name/effects only
 * name[en]=foo      → full-text on English name/effects only
 *
 * If the backend returns null (unavailable / NullSearchBackend), falls back
 * to a PostgreSQL LIKE query on cardGroup.translations.name.
 */
final class CardNameFilter extends AbstractFilter
{
    private SearchBackendInterface $searchBackend;
    private ?FilterProfiler $profiler = null;

    #[Required]
    public function setSearchBackend(SearchBackendInterface $searchBackend): void
    {
        $this->searchBackend = $searchBackend;
    }

    #[Required]
    public function setProfiler(FilterProfiler $profiler): void
    {
        $this->profiler = $profiler;
    }

    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ($property !== 'name' || $value === null || $value === '' || $value === []) {
            return;
        }

        $root = $queryBuilder->getRootAliases()[0];
        $isCardGroup = CardGroup::class === $resourceClass;

        // ── Search backend fast path ─────────────────────────────────────────
        $this->profiler?->start('name', $this->searchBackend::class);
        $ids = $this->resolveWithBackend($value);

        if ($ids !== null) {
            $this->profiler?->stop('name', count($ids));
            if (empty($ids)) {
                $queryBuilder->andWhere('1 = 0');
                return;
            }

            $p = $queryNameGenerator->generateParameterName('search_ids');
            $queryBuilder->setParameter($p, $ids);

            if ($isCardGroup) {
                // Meilisearch index stores Card IDs; resolve to CardGroups that have a matching card
                $queryBuilder->andWhere(
                    "EXISTS (SELECT 1 FROM " . Card::class . " _src WHERE _src.cardGroup = $root AND _src.id IN (:$p))"
                );
            } else {
                $queryBuilder->andWhere("$root.id IN (:$p)");
            }

            return;
        }

        $this->profiler?->stop('name', null);

        // ── PostgreSQL LIKE fallback ─────────────────────────────────────────
        $cgAlias = $isCardGroup ? $root : $this->getOrJoinCardGroup($queryBuilder, $root);

        if (is_string($value)) {
            $search = trim($value);
            if ($search === '') return;

            // CardGroup has translations directly (EAGER loaded), no need to join
            if ($isCardGroup) {
                $pName = $queryNameGenerator->generateParameterName('name_search');
                $subDql = sprintf(
                    'SELECT 1 FROM %s t WHERE t.cardGroup = %s AND LOWER(t.name) LIKE :%s',
                    CardGroupTranslation::class,
                    $root,
                    $pName,
                );
                $queryBuilder
                    ->andWhere($queryBuilder->expr()->exists($subDql))
                    ->setParameter($pName, '%' . mb_strtolower($search) . '%');
                return;
            }

            // Card entity - need to go through cardGroup
            $tAlias = $queryNameGenerator->generateJoinAlias('cgt');
            $pName  = $queryNameGenerator->generateParameterName('name_search');
            $queryBuilder
                ->leftJoin("$cgAlias.translations", $tAlias)
                ->andWhere($queryBuilder->expr()->like("LOWER($tAlias.name)", ":$pName"))
                ->setParameter($pName, '%' . mb_strtolower($search) . '%');
            return;
        }

        // Use EXISTS subqueries to avoid JOIN WITH conditions on EAGER associations.
        $orParts = [];
        foreach ($value as $locale => $search) {
            $search = trim((string) $search);
            if ($search === '') continue;

            $pLoc   = $queryNameGenerator->generateParameterName('name_locale');
            $pName  = $queryNameGenerator->generateParameterName('name_search');

            // CardGroup - already on CardGroup entity
            if ($isCardGroup) {
                $subDql = sprintf(
                    'SELECT 1 FROM %s t WHERE t.cardGroup = %s AND t.locale = :%s AND LOWER(t.name) LIKE :%s',
                    CardGroupTranslation::class,
                    $root,
                    $pLoc,
                    $pName,
                );
            } else {
                $tAlias = $queryNameGenerator->generateJoinAlias('cgt');
                $subDql = sprintf(
                    'SELECT 1 FROM %s %s WHERE %s.cardGroup = %s AND %s.locale = :%s AND LOWER(%s.name) LIKE :%s',
                    CardGroupTranslation::class, $tAlias,
                    $tAlias, $cgAlias,
                    $tAlias, $pLoc,
                    $tAlias, $pName,
                );
            }

            $orParts[] = $queryBuilder->expr()->exists($subDql);
            $queryBuilder
                ->setParameter($pLoc, $locale)
                ->setParameter($pName, '%' . mb_strtolower($search) . '%');
        }

        if (!empty($orParts)) {
            $queryBuilder->andWhere($queryBuilder->expr()->orX(...$orParts));
        }
    }

    /**
     * Ask the backend for matching IDs.
     * Returns null if the backend is unavailable (triggers LIKE fallback).
     *
     * @return int[]|null
     */
    private function resolveWithBackend(string|array $value): ?array
    {
        if (is_string($value)) {
            return $this->searchBackend->searchCardIds(trim($value));
        }

        // locale-specific search — OR between locales
        $allIds = null;
        foreach ($value as $locale => $search) {
            $search = trim((string) $search);
            if ($search === '') continue;

            $ids = $this->searchBackend->searchCardIds($search, [
                "name_{$locale}",
                "main_effect_{$locale}",
                "echo_effect_{$locale}",
            ]);
            if ($ids === null) {
                return null; // backend unavailable, trigger fallback
            }

            $allIds = $allIds === null
                ? $ids
                : array_values(array_unique(array_merge($allIds, $ids)));
        }

        return $allIds;
    }

    private function getOrJoinCardGroup(QueryBuilder $qb, string $root): string
    {
        foreach ($qb->getDQLPart('join')[$root] ?? [] as $join) {
            if ($join->getJoin() === "$root.cardGroup") {
                return $join->getAlias();
            }
        }
        $alias = 'alias_cg_name';
        $qb->join("$root.cardGroup", $alias);
        return $alias;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'name' => [
                'property'    => 'name',
                'type'        => 'string',
                'required'    => false,
                'description' => 'Full-text search across all locales (Meilisearch)',
            ],
            'name[fr]' => [
                'property' => 'name',
                'type'     => 'string',
                'required' => false,
            ],
            'name[en]' => [
                'property' => 'name',
                'type'     => 'string',
                'required' => false,
            ],
        ];
    }
}
