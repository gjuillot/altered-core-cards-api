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
 * Filters cards that have at least N effect slots sharing the same abilityTrigger.
 *
 * ?minSameTriggerCount=2  → at least 2 effect slots have the same trigger
 * ?minSameTriggerCount=3  → all 3 effect slots have the same trigger
 */
final class SameTriggerCountFilter extends AbstractFilter
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

        $minCount = (int) $value;
        if ($minCount < 2 || $minCount > 3) {
            return;
        }

        if ($resourceClass === Card::class) {
            $this->profiler?->start('sameTrigger', 'card_search');
            $this->filterViaCardSearch($minCount, $queryBuilder);
            return;
        }

        $this->profiler?->start('sameTrigger', 'join');
        $root    = $queryBuilder->getRootAliases()[0];
        $through = $this->properties[$property] ?? null;

        if ($through) {
            $throughAlias = $queryNameGenerator->generateJoinAlias($through);
            $queryBuilder->leftJoin("$root.$through", $throughAlias);
            $joinRoot = $throughAlias;
        } else {
            $joinRoot = $root;
        }

        $a1 = $queryNameGenerator->generateJoinAlias('effect1');
        $a2 = $queryNameGenerator->generateJoinAlias('effect2');
        $a3 = $queryNameGenerator->generateJoinAlias('effect3');

        $queryBuilder
            ->leftJoin("$joinRoot.effect1", $a1)
            ->leftJoin("$joinRoot.effect2", $a2)
            ->leftJoin("$joinRoot.effect3", $a3);

        if ($minCount === 3) {
            $queryBuilder->andWhere(
                "$a1.abilityTrigger IS NOT NULL
                 AND $a2.abilityTrigger IS NOT NULL
                 AND $a3.abilityTrigger IS NOT NULL
                 AND $a1.abilityTrigger = $a2.abilityTrigger
                 AND $a2.abilityTrigger = $a3.abilityTrigger"
            );
        } else {
            $queryBuilder->andWhere(
                "($a1.abilityTrigger IS NOT NULL AND $a2.abilityTrigger IS NOT NULL AND $a1.abilityTrigger = $a2.abilityTrigger)
                 OR ($a1.abilityTrigger IS NOT NULL AND $a3.abilityTrigger IS NOT NULL AND $a1.abilityTrigger = $a3.abilityTrigger)
                 OR ($a2.abilityTrigger IS NOT NULL AND $a3.abilityTrigger IS NOT NULL AND $a2.abilityTrigger = $a3.abilityTrigger)"
            );
        }
        $this->profiler?->stop('sameTrigger');
    }

    private function filterViaCardSearch(int $minCount, QueryBuilder $qb): void
    {
        if ($minCount === 3) {
            $dqlCondition = 'cs.t1 IS NOT NULL AND cs.t2 IS NOT NULL AND cs.t3 IS NOT NULL AND cs.t1 = cs.t2 AND cs.t2 = cs.t3';
        } else {
            $dqlCondition = '(cs.t1 IS NOT NULL AND cs.t2 IS NOT NULL AND cs.t1 = cs.t2)
                              OR (cs.t1 IS NOT NULL AND cs.t3 IS NOT NULL AND cs.t1 = cs.t3)
                              OR (cs.t2 IS NOT NULL AND cs.t3 IS NOT NULL AND cs.t2 = cs.t3)';
        }

        $root = $qb->getRootAliases()[0];
        $qb->andWhere(
            "$root.id IN (SELECT IDENTITY(cs.cardId) FROM " . CardSearch::class . " cs WHERE $dqlCondition)"
        );
        $this->profiler?->stop('sameTrigger');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'minSameTriggerCount' => [
                'property'    => 'minSameTriggerCount',
                'type'        => 'int',
                'required'    => false,
                'description' => 'Minimum number of effect slots sharing the same trigger type (2 or 3)',
            ],
        ];
    }
}
