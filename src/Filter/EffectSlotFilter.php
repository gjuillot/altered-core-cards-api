<?php

namespace App\Filter;

use App\Debug\FilterProfiler;
use App\Entity\Card;
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

        $this->profiler?->start('effectSlot', 'join');
        $this->filterViaJoin($value, $mode, $queryBuilder, $queryNameGenerator);
        $this->profiler?->stop('effectSlot');
    }

    // ── Fast path (Card) ────────────────────────────────────────────────────

    private function filterViaCardSearch(array $value, string $mode, QueryBuilder $qb): void
    {
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
            foreach ([1, 2, 3] as $s) {
                $parts = [];
                if ($trigger > 0)   $parts[] = "t$s = $trigger";
                if ($condition > 0) $parts[] = "c$s = $condition";
                if ($effect > 0)    $parts[] = "e$s = $effect";
                if ($parts) {
                    $slotExprs[] = '(' . implode(' AND ', $parts) . ')';
                }
            }

            if ($slotExprs) {
                $criteriaExprs[] = '(' . implode(' OR ', $slotExprs) . ')';
            }
        }

        if (empty($criteriaExprs)) {
            $this->profiler?->stop('effectSlot');
            return;
        }

        $glue = $mode === 'and' ? ' AND ' : ' OR ';
        $root = $qb->getRootAliases()[0];
        $dqlConditions = implode($glue, $criteriaExprs);

        $qb->andWhere(
            "$root.id IN (SELECT IDENTITY(cs.cardId) FROM " . CardSearch::class . " cs WHERE $dqlConditions)"
        );
        $this->profiler?->stop('effectSlot');
    }

    // ── Fallback path (CardGroup or other) ─────────────────────────────────

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
