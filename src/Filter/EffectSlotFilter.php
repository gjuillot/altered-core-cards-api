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
 * Filters by effect slot composition (trigger + condition + effect IDs).
 *
 * URL: ?effectSlot[0][trigger]=5&effectSlot[0][condition]=3&effectSlot[1][trigger]=7&effectSlotMode=or
 * Modes: or (default) | and
 *
 * On Card: fast DBAL lookup on card_search flat table.
 * On CardGroup: JOIN-based fallback.
 */
final class EffectSlotFilter extends AbstractFilter
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
        if (!$this->isPropertyEnabled($property, $resourceClass) || !is_array($value) || empty($value)) {
            return;
        }

        $mode = strtolower($context['filters']['effectSlotMode'] ?? 'or');

        if ($resourceClass === Card::class) {
            $this->profiler?->start('effectSlot', 'card_search');
            $this->filterViaCardSearch($value, $mode, $queryBuilder);
            return;
        }

        if ($resourceClass === CardGroup::class) {
            $this->profiler?->start('effectSlot', 'card_group_search');
            $this->filterViaCardGroupSearch($value, $mode, $queryBuilder);
            $this->profiler?->stop('effectSlot');
            return;
        }

        $this->profiler?->start('effectSlot', 'join');
        $this->filterViaJoin($value, $mode, $queryBuilder, $queryNameGenerator);
        $this->profiler?->stop('effectSlot');
    }

    // ── Fast path (Card) ────────────────────────────────────────────────────

    private function filterViaCardSearch(array $value, string $mode, QueryBuilder $qb): void
    {
        $root = $qb->getRootAliases()[0];

        $triggerMap   = $this->resolveAlteredIds('ability_trigger',   array_column(array_filter($value, 'is_array'), 'trigger'));
        $conditionMap = $this->resolveAlteredIds('ability_condition',  array_column(array_filter($value, 'is_array'), 'condition'));
        $effectMap    = $this->resolveAlteredIds('ability_effect',     array_column(array_filter($value, 'is_array'), 'effect'));

        $resolved = $this->buildResolvedCriteria($value, $triggerMap, $conditionMap, $effectMap);

        if (empty($resolved)) {
            $this->profiler?->stop('effectSlot');
            return;
        }

        if ($mode === 'and' && count($resolved) > 1) {
            $cond = $this->buildDistinctAndCondition($resolved, 'cs');
        } else {
            $exprs = array_map(fn($c) => $this->buildSlotOrExpr($c, 'cs'), $resolved);
            $cond  = implode($mode === 'and' ? ' AND ' : ' OR ', $exprs);
        }

        $qb->andWhere(
            "EXISTS (SELECT cs FROM " . CardSearch::class . " cs WHERE cs.cardId = $root.id AND ($cond))"
        );
        $this->profiler?->stop('effectSlot');
    }

    // ── CardGroup path — card_search via EXISTS subquery ───────────────────

    private function filterViaCardGroupSearch(array $value, string $mode, QueryBuilder $qb): void
    {
        $root = $qb->getRootAliases()[0];

        $triggerMap   = $this->resolveAlteredIds('ability_trigger',  array_column(array_filter($value, 'is_array'), 'trigger'));
        $conditionMap = $this->resolveAlteredIds('ability_condition', array_column(array_filter($value, 'is_array'), 'condition'));
        $effectMap    = $this->resolveAlteredIds('ability_effect',    array_column(array_filter($value, 'is_array'), 'effect'));

        $resolved = $this->buildResolvedCriteria($value, $triggerMap, $conditionMap, $effectMap);

        if (empty($resolved)) {
            return;
        }

        if ($mode === 'and' && count($resolved) > 1) {
            // All criteria must be on distinct slots — single EXISTS covers all permutations
            $cond = $this->buildDistinctAndCondition($resolved, '_cs');
            $qb->andWhere(
                "EXISTS (SELECT _c FROM " . Card::class . " _c WHERE _c.cardGroup = $root"
                . " AND EXISTS (SELECT _cs FROM " . CardSearch::class . " _cs WHERE _cs.cardId = _c.id AND ($cond)))"
            );
        } else {
            // OR mode or single criterion — one EXISTS per criterion
            $exprs = [];
            foreach ($resolved as $crit) {
                $slotCond = $this->buildSlotOrExpr($crit, '_cs');
                $exprs[]  = "EXISTS (SELECT _c FROM " . Card::class . " _c WHERE _c.cardGroup = $root"
                    . " AND EXISTS (SELECT _cs FROM " . CardSearch::class . " _cs WHERE _cs.cardId = _c.id AND ($slotCond)))";
            }
            $glue = $mode === 'and' ? ' AND ' : ' OR ';
            $qb->andWhere(implode($glue, $exprs));
        }
    }

    // ── Slot helpers ────────────────────────────────────────────────────────

    /** Resolve $value array to [['t'=>id,'c'=>id,'e'=>id], ...] with internal IDs */
    private function buildResolvedCriteria(array $value, array $triggerMap, array $conditionMap, array $effectMap): array
    {
        $resolved = [];
        foreach ($value as $criteria) {
            if (!is_array($criteria)) continue;
            $t = $triggerMap[(int)   ($criteria['trigger']   ?? 0)] ?? 0;
            $c = $conditionMap[(int) ($criteria['condition'] ?? 0)] ?? 0;
            $e = $effectMap[(int)    ($criteria['effect']    ?? 0)] ?? 0;
            if ($t === 0 && $c === 0 && $e === 0) continue;
            $resolved[] = ['t' => $t, 'c' => $c, 'e' => $e];
        }
        return $resolved;
    }

    /** "(slot1 matches crit) OR (slot2 matches crit) OR ..." — any slot satisfies the criterion */
    private function buildSlotOrExpr(array $crit, string $alias): string
    {
        $slots = [
            ['t' => "$alias.t1",  'c' => "$alias.c1",  'e' => "$alias.e1"],
            ['t' => "$alias.t2",  'c' => "$alias.c2",  'e' => "$alias.e2"],
            ['t' => "$alias.t3",  'c' => "$alias.c3",  'e' => "$alias.e3"],
            ['t' => "$alias.et1", 'c' => "$alias.ec1", 'e' => "$alias.ee1"],
        ];
        $exprs = [];
        foreach ($slots as $slot) {
            $parts = [];
            if ($crit['t'] > 0) $parts[] = "{$slot['t']} = {$crit['t']}";
            if ($crit['c'] > 0) $parts[] = "{$slot['c']} = {$crit['c']}";
            if ($crit['e'] > 0) $parts[] = "{$slot['e']} = {$crit['e']}";
            if ($parts) $exprs[] = '(' . implode(' AND ', $parts) . ')';
        }
        return $exprs ? '(' . implode(' OR ', $exprs) . ')' : '1=0';
    }

    /**
     * For AND mode: each criterion must be satisfied by a DISTINCT slot.
     * Generates all permutations of slot assignments and OR's them.
     * 2 criteria → 12 combinations, 3 criteria → 24 combinations.
     */
    private function buildDistinctAndCondition(array $resolved, string $alias): string
    {
        $slots = [
            ['t' => "$alias.t1",  'c' => "$alias.c1",  'e' => "$alias.e1"],
            ['t' => "$alias.t2",  'c' => "$alias.c2",  'e' => "$alias.e2"],
            ['t' => "$alias.t3",  'c' => "$alias.c3",  'e' => "$alias.e3"],
            ['t' => "$alias.et1", 'c' => "$alias.ec1", 'e' => "$alias.ee1"],
        ];

        $permExprs = [];
        foreach ($this->slotPermutations([0, 1, 2, 3], count($resolved)) as $perm) {
            $andParts = [];
            foreach ($resolved as $i => $crit) {
                $slot  = $slots[$perm[$i]];
                $parts = [];
                if ($crit['t'] > 0) $parts[] = "{$slot['t']} = {$crit['t']}";
                if ($crit['c'] > 0) $parts[] = "{$slot['c']} = {$crit['c']}";
                if ($crit['e'] > 0) $parts[] = "{$slot['e']} = {$crit['e']}";
                if (!$parts) { $andParts = null; break; }
                $andParts[] = '(' . implode(' AND ', $parts) . ')';
            }
            if ($andParts !== null) {
                $permExprs[] = '(' . implode(' AND ', $andParts) . ')';
            }
        }

        return $permExprs ? '(' . implode(' OR ', $permExprs) . ')' : '1=0';
    }

    /**
     * Returns all ways to pick $k distinct elements from $pool in order.
     * slotPermutations([0,1,2,3], 2) → [[0,1],[0,2],[0,3],[1,0],[1,2],...] (12 results)
     */
    private function slotPermutations(array $pool, int $k): array
    {
        if ($k === 0) return [[]];
        $result = [];
        foreach ($pool as $item) {
            $remaining = array_values(array_filter($pool, fn($x) => $x !== $item));
            foreach ($this->slotPermutations($remaining, $k - 1) as $rest) {
                $result[] = array_merge([$item], $rest);
            }
        }
        return $result;
    }

    // ── Fallback path (other entities) ─────────────────────────────────────

    private function filterViaJoin(
        array $value,
        string $mode,
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
    ): void {
        $root = $qb->getRootAliases()[0];

        if (property_exists($qb->getRootEntities()[0] ?? '', 'effect1')) {
            $cgAlias = $root;
        } else {
            $cgAlias = $qng->generateJoinAlias('cg');
            $qb->leftJoin("$root.cardGroup", $cgAlias);
        }

        $criteriaExprs = [];

        $triggerMap   = $this->resolveAlteredIds('ability_trigger',   array_column(array_filter($value, 'is_array'), 'trigger'));
        $conditionMap = $this->resolveAlteredIds('ability_condition',  array_column(array_filter($value, 'is_array'), 'condition'));
        $effectMap    = $this->resolveAlteredIds('ability_effect',     array_column(array_filter($value, 'is_array'), 'effect'));

        foreach ($value as $criteria) {
            if (!is_array($criteria)) {
                continue;
            }

            $trigger   = $triggerMap[(int)   ($criteria['trigger']   ?? 0)] ?? 0;
            $condition = $conditionMap[(int) ($criteria['condition'] ?? 0)] ?? 0;
            $effect    = $effectMap[(int)    ($criteria['effect']    ?? 0)] ?? 0;

            if ($trigger === 0 && $condition === 0 && $effect === 0) {
                continue;
            }

            $slotExprs = [];

            foreach (['e1', 'e2', 'e3'] as $i => $eAlias) {
                $slot = $i + 1;
                $a    = $qng->generateJoinAlias("effect$slot");
                $qb->leftJoin("$cgAlias.effect$slot", $a);

                $parts = [];
                if ($trigger > 0) {
                    $p = $qng->generateParameterName("t$slot");
                    $parts[] = "IDENTITY($a.abilityTrigger) = :$p";
                    $qb->setParameter($p, $trigger);
                }
                if ($condition > 0) {
                    $p = $qng->generateParameterName("c$slot");
                    $parts[] = "IDENTITY($a.abilityCondition) = :$p";
                    $qb->setParameter($p, $condition);
                }
                if ($effect > 0) {
                    $p = $qng->generateParameterName("e$slot");
                    $parts[] = "IDENTITY($a.abilityEffect) = :$p";
                    $qb->setParameter($p, $effect);
                }
                if ($parts) {
                    $slotExprs[] = '(' . implode(' AND ', $parts) . ')';
                }
            }

            // echo slot
            $ae = $qng->generateJoinAlias('echoEffect1');
            $qb->leftJoin("$cgAlias.echoEffect1", $ae);
            $echoParts = [];
            if ($trigger > 0) {
                $p = $qng->generateParameterName('et');
                $echoParts[] = "IDENTITY($ae.abilityTrigger) = :$p";
                $qb->setParameter($p, $trigger);
            }
            if ($condition > 0) {
                $p = $qng->generateParameterName('ec');
                $echoParts[] = "IDENTITY($ae.abilityCondition) = :$p";
                $qb->setParameter($p, $condition);
            }
            if ($effect > 0) {
                $p = $qng->generateParameterName('ee');
                $echoParts[] = "IDENTITY($ae.abilityEffect) = :$p";
                $qb->setParameter($p, $effect);
            }
            if ($echoParts) {
                $slotExprs[] = '(' . implode(' AND ', $echoParts) . ')';
            }

            if ($slotExprs) {
                $criteriaExprs[] = '(' . implode(' OR ', $slotExprs) . ')';
            }
        }

        if (empty($criteriaExprs)) {
            return;
        }

        $glue = $mode === 'and' ? ' AND ' : ' OR ';
        $qb->andWhere('(' . implode($glue, $criteriaExprs) . ')');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'effectSlot[N][trigger]'   => ['property' => 'effectSlot', 'type' => 'int', 'required' => false, 'description' => 'ability_trigger alteredId (0 = any)'],
            'effectSlot[N][condition]' => ['property' => 'effectSlot', 'type' => 'int', 'required' => false, 'description' => 'ability_condition alteredId (0 = any)'],
            'effectSlot[N][effect]'    => ['property' => 'effectSlot', 'type' => 'int', 'required' => false, 'description' => 'ability_effect alteredId (0 = any)'],
            'effectSlotMode'           => ['property' => 'effectSlotMode', 'type' => 'string', 'required' => false, 'description' => 'or (default) | and'],
        ];
    }
}
