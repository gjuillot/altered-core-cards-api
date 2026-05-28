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
 * Filters by presence or absence of effects.
 *
 * ?hasNoEffect=true   → cards with no effects at all
 * ?hasNoEffect=false  → cards with at least one effect
 */
final class HasNoEffectFilter extends AbstractFilter
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

        $noEffect = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($noEffect === null) {
            return;
        }

        if ($resourceClass === Card::class) {
            $this->profiler?->start('hasNoEffect', 'card_search');
            $this->filterViaCardSearch($noEffect, $queryBuilder);
            return;
        }

        $this->profiler?->start('hasNoEffect', 'join');
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

        if ($noEffect) {
            $queryBuilder->andWhere("$a1.id IS NULL AND $a2.id IS NULL AND $a3.id IS NULL");
        } else {
            $queryBuilder->andWhere("$a1.id IS NOT NULL OR $a2.id IS NOT NULL OR $a3.id IS NOT NULL");
        }
        $this->profiler?->stop('hasNoEffect');
    }

    private function filterViaCardSearch(bool $noEffect, QueryBuilder $qb): void
    {
        $root = $qb->getRootAliases()[0];
        $qb->andWhere(
            "EXISTS (SELECT cs FROM " . CardSearch::class . " cs WHERE cs.cardId = $root.id AND cs.hasEffect = :csHasEffect)"
        );
        $qb->setParameter('csHasEffect', !$noEffect);
        $this->profiler?->stop('hasNoEffect');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'hasNoEffect' => [
                'property'    => 'hasNoEffect',
                'type'        => 'bool',
                'required'    => false,
                'description' => 'true = cards with no effects; false = cards with at least one effect',
            ],
        ];
    }
}
