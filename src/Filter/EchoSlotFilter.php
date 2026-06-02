<?php

namespace App\Filter;

use App\Debug\FilterProfiler;
use App\Entity\Card;
use App\Entity\CardGroup;
use App\Entity\CardSearch;
use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Two behaviours in one filter:
 *
 * 1) Presence check
 *    ?hasEchoEffect=true   → cards that have an echo effect slot populated
 *    ?hasEchoEffect=false  → cards that have no echo effect slot
 *
 * 2) Slot composition search (multiple criteria, same syntax as effectSlot)
 *    ?echoSlot[0][trigger]=5&echoSlot[0][condition]=3
 *    ?echoSlot[0][trigger]=5&echoSlot[1][trigger]=7&echoSlotMode=or
 *    Each criterion is AND internally; criteria are combined with echoSlotMode (or = default, and).
 *    alteredId values; omit a key to ignore that component.
 */
final class EchoSlotFilter extends AbstractFilter
{
    use CardSearchInClauseTrait;

    private ?FilterProfiler $profiler = null;

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
        if (!$this->isPropertyEnabled($property, $resourceClass) || $value === '' || $value === null) {
            return;
        }

        if ($property === 'hasEchoEffect') {
            $this->applyPresenceFilter($value, $queryBuilder, $queryNameGenerator, $resourceClass);
            return;
        }

        if ($property === 'echoSlot' && is_array($value) && !empty($value)) {
            $mode = strtolower($context['filters']['echoSlotMode'] ?? 'or');
            $this->applySlotFilter($value, $mode, $queryBuilder, $queryNameGenerator, $resourceClass);
        }
    }

    // ── Presence filter ─────────────────────────────────────────────────────

    private function applyPresenceFilter(
        mixed $value,
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $resourceClass,
    ): void {
        $hasEcho = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($hasEcho === null) {
            return;
        }

        $root = $qb->getRootAliases()[0];

        if ($resourceClass === Card::class) {
            $this->profiler?->start('hasEchoEffect', 'card_search');
            $condition = $hasEcho
                ? '(cs.et1 IS NOT NULL OR cs.ec1 IS NOT NULL OR cs.ee1 IS NOT NULL)'
                : '(cs.et1 IS NULL AND cs.ec1 IS NULL AND cs.ee1 IS NULL)';
            $qb->andWhere("EXISTS (SELECT cs FROM " . CardSearch::class . " cs WHERE cs.cardId = $root.id AND $condition)");
            $this->profiler?->stop('hasEchoEffect');
            return;
        }

        $this->profiler?->start('hasEchoEffect', 'join');
        $cgAlias = $this->resolveCardGroupAlias($root, $qb, $qng, $resourceClass);
        $ae      = $qng->generateJoinAlias('echoEffect1');
        $qb->leftJoin("$cgAlias.echoEffect1", $ae);

        if ($hasEcho) {
            $qb->andWhere("$ae.id IS NOT NULL");
        } else {
            $qb->andWhere("$ae.id IS NULL");
        }
        $this->profiler?->stop('hasEchoEffect');
    }

    // ── Slot composition filter ──────────────────────────────────────────────

    private function applySlotFilter(
        array $value,
        string $mode,
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $resourceClass,
    ): void {
        // Resolve all alteredIds up front to avoid N queries
        $triggerMap   = $this->resolveAlteredIds('ability_trigger',  array_column(array_filter($value, 'is_array'), 'trigger'));
        $conditionMap = $this->resolveAlteredIds('ability_condition', array_column(array_filter($value, 'is_array'), 'condition'));
        $effectMap    = $this->resolveAlteredIds('ability_effect',    array_column(array_filter($value, 'is_array'), 'effect'));

        $root = $qb->getRootAliases()[0];
        $criteriaExprs = [];

        if ($resourceClass === Card::class) {
            $this->profiler?->start('echoSlot', 'card_search');

            foreach ($value as $criteria) {
                if (!is_array($criteria)) continue;

                $trigger   = $triggerMap[(int)   ($criteria['trigger']   ?? 0)] ?? 0;
                $condition = $conditionMap[(int) ($criteria['condition'] ?? 0)] ?? 0;
                $effect    = $effectMap[(int)    ($criteria['effect']    ?? 0)] ?? 0;

                if ($trigger === 0 && $condition === 0 && $effect === 0) continue;

                $parts = [];
                if ($trigger > 0)   $parts[] = "cs.et1 = $trigger";
                if ($condition > 0) $parts[] = "cs.ec1 = $condition";
                if ($effect > 0)    $parts[] = "cs.ee1 = $effect";

                $criteriaExprs[] = '(' . implode(' AND ', $parts) . ')';
            }

            if (empty($criteriaExprs)) {
                $this->profiler?->stop('echoSlot');
                return;
            }

            $glue = $mode === 'and' ? ' AND ' : ' OR ';
            $qb->andWhere(
                "EXISTS (SELECT cs FROM " . CardSearch::class . " cs WHERE cs.cardId = $root.id AND (" . implode($glue, $criteriaExprs) . "))"
            );
            $this->profiler?->stop('echoSlot');
            return;
        }

        if ($resourceClass === CardGroup::class) {
            $this->profiler?->start('echoSlot', 'card_group_search');

            foreach ($value as $criteria) {
                if (!is_array($criteria)) continue;

                $trigger   = $triggerMap[(int)   ($criteria['trigger']   ?? 0)] ?? 0;
                $condition = $conditionMap[(int) ($criteria['condition'] ?? 0)] ?? 0;
                $effect    = $effectMap[(int)    ($criteria['effect']    ?? 0)] ?? 0;

                if ($trigger === 0 && $condition === 0 && $effect === 0) continue;

                $parts = [];
                if ($trigger > 0)   $parts[] = "_cs.et1 = $trigger";
                if ($condition > 0) $parts[] = "_cs.ec1 = $condition";
                if ($effect > 0)    $parts[] = "_cs.ee1 = $effect";

                if ($parts) {
                    $criteriaExprs[] = '(' . implode(' AND ', $parts) . ')';
                }
            }

            if (empty($criteriaExprs)) {
                $this->profiler?->stop('echoSlot');
                return;
            }

            $glue = $mode === 'and' ? ' AND ' : ' OR ';
            $dqlCond = implode($glue, $criteriaExprs);
            $qb->andWhere(
                "EXISTS (SELECT _c FROM " . Card::class . " _c WHERE _c.cardGroup = $root"
                . " AND EXISTS (SELECT _cs FROM " . CardSearch::class . " _cs WHERE _cs.cardId = _c.id AND ($dqlCond)))"
            );
            $this->profiler?->stop('echoSlot');
            return;
        }

        $this->profiler?->start('echoSlot', 'join');
        $cgAlias = $this->resolveCardGroupAlias($root, $qb, $qng, $resourceClass);

        foreach ($value as $criteria) {
            if (!is_array($criteria)) continue;

            $trigger   = $triggerMap[(int)   ($criteria['trigger']   ?? 0)] ?? 0;
            $condition = $conditionMap[(int) ($criteria['condition'] ?? 0)] ?? 0;
            $effect    = $effectMap[(int)    ($criteria['effect']    ?? 0)] ?? 0;

            if ($trigger === 0 && $condition === 0 && $effect === 0) continue;

            $ae = $qng->generateJoinAlias('echoEffect1');
            $qb->leftJoin("$cgAlias.echoEffect1", $ae);

            $parts = [];
            if ($trigger > 0) {
                $p = $qng->generateParameterName('et');
                $parts[] = "IDENTITY($ae.abilityTrigger) = :$p";
                $qb->setParameter($p, $trigger);
            }
            if ($condition > 0) {
                $p = $qng->generateParameterName('ec');
                $parts[] = "IDENTITY($ae.abilityCondition) = :$p";
                $qb->setParameter($p, $condition);
            }
            if ($effect > 0) {
                $p = $qng->generateParameterName('ee');
                $parts[] = "IDENTITY($ae.abilityEffect) = :$p";
                $qb->setParameter($p, $effect);
            }

            if ($parts) {
                $criteriaExprs[] = '(' . implode(' AND ', $parts) . ')';
            }
        }

        if (empty($criteriaExprs)) {
            $this->profiler?->stop('echoSlot');
            return;
        }

        $glue = $mode === 'and' ? ' AND ' : ' OR ';
        $qb->andWhere('(' . implode($glue, $criteriaExprs) . ')');
        $this->profiler?->stop('echoSlot');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveCardGroupAlias(
        string $root,
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $resourceClass,
    ): string {
        if (property_exists($qb->getRootEntities()[0] ?? '', 'echoEffect1')) {
            return $root;
        }
        $cgAlias = $qng->generateJoinAlias('cg');
        $qb->leftJoin("$root.cardGroup", $cgAlias);
        return $cgAlias;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'hasEchoEffect'             => ['property' => 'hasEchoEffect', 'type' => 'bool',   'required' => false, 'description' => 'true = has echo effect; false = no echo effect'],
            'echoSlot[N][trigger]'      => ['property' => 'echoSlot',      'type' => 'int',    'required' => false, 'description' => 'ability_trigger alteredId in echo slot'],
            'echoSlot[N][condition]'    => ['property' => 'echoSlot',      'type' => 'int',    'required' => false, 'description' => 'ability_condition alteredId in echo slot'],
            'echoSlot[N][effect]'       => ['property' => 'echoSlot',      'type' => 'int',    'required' => false, 'description' => 'ability_effect alteredId in echo slot'],
            'echoSlotMode'              => ['property' => 'echoSlotMode',   'type' => 'string', 'required' => false, 'description' => 'or (default) | and'],
        ];
    }
}
