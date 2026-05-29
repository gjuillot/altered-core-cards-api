<?php

namespace App\Filter;

use App\Debug\FilterProfiler;
use App\Entity\Card;
use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Filters by keyword(s) across effect1/2/3.
 *
 * Single:  ?effectKeyword=CORIACE
 * Multi:   ?effectKeyword[]=CORIACE&effectKeyword[]=FUGACE&effectKeywordMode=and|or  (default: or)
 *
 * On Card: DBAL lookup on card_search.keywords TEXT[] with GIN index.
 *   OR  → keywords && ARRAY['KW1','KW2']
 *   AND → keywords @> ARRAY['KW1','KW2']
 *
 * On CardGroup: JSONB_CONTAINS join-based fallback.
 */
final class EffectKeywordFilter extends AbstractFilter
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
        if (!$this->isPropertyEnabled($property, $resourceClass) || empty($value)) {
            return;
        }

        $keywords = array_values(array_filter(
            array_map('strtoupper', is_array($value) ? $value : [$value])
        ));
        if (empty($keywords)) {
            return;
        }

        $mode = strtolower($context['filters']['effectKeywordMode'] ?? 'or');

        if ($resourceClass === Card::class) {
            $this->profiler?->start('keyword', 'card_search');
            $this->filterViaCardSearch($keywords, $mode, $queryBuilder);
            return;
        }

        $this->profiler?->start('keyword', 'join');
        $this->filterViaJoin($keywords, $mode, $queryBuilder, $queryNameGenerator, $property);
    }

    // ── Fast path (Card) ────────────────────────────────────────────────────

    private function filterViaCardSearch(array $keywords, string $mode, QueryBuilder $qb): void
    {
        // Escape single quotes
        $escaped = array_map(fn(string $k) => str_replace("'", "''", $k), $keywords);
        $arr     = "ARRAY['" . implode("','", $escaped) . "']";

        // AND → @> (contains all)   OR → && (overlaps / contains any)
        $op  = $mode === 'and' ? '@>' : '&&';
        $sql = "SELECT card_id FROM card_search WHERE keywords $op $arr";

        $conn = $this->managerRegistry->getManager()->getConnection();
        $ids  = $conn->fetchFirstColumn($sql) ?: [0];

        $root = $qb->getRootAliases()[0];
        $this->applyIdInClause($qb, $root, $ids);
        $this->profiler?->stop('keyword', count($ids));
    }

    // ── Fallback path (CardGroup) ───────────────────────────────────────────

    private function filterViaJoin(
        array $keywords,
        string $mode,
        QueryBuilder $qb,
        QueryNameGeneratorInterface $qng,
        string $property,
    ): void {
        $root    = $qb->getRootAliases()[0];
        $through = $this->properties[$property] ?? null;

        if ($through) {
            $throughAlias = $qng->generateJoinAlias($through);
            $qb->leftJoin("$root.$through", $throughAlias);
            $joinRoot = $throughAlias;
        } else {
            $joinRoot = $root;
        }

        $a1 = $qng->generateJoinAlias('effect1');
        $a2 = $qng->generateJoinAlias('effect2');
        $a3 = $qng->generateJoinAlias('effect3');
        $ae = $qng->generateJoinAlias('echoEffect1');

        $qb->leftJoin("$joinRoot.effect1", $a1)
           ->leftJoin("$joinRoot.effect2", $a2)
           ->leftJoin("$joinRoot.effect3", $a3)
           ->leftJoin("$joinRoot.echoEffect1", $ae);

        if ($mode === 'and') {
            foreach ($keywords as $i => $kw) {
                $p = $qng->generateParameterName($property . $i);
                $qb->andWhere(
                    "JSONB_CONTAINS($a1.keywords, :$p) = true
                     OR JSONB_CONTAINS($a2.keywords, :$p) = true
                     OR JSONB_CONTAINS($a3.keywords, :$p) = true
                     OR JSONB_CONTAINS($ae.keywords, :$p) = true"
                )->setParameter($p, json_encode([['k' => $kw]]));
            }
        } else {
            $orClauses = [];
            foreach ($keywords as $i => $kw) {
                $p = $qng->generateParameterName($property . $i);
                $orClauses[] = "JSONB_CONTAINS($a1.keywords, :$p) = true
                                OR JSONB_CONTAINS($a2.keywords, :$p) = true
                                OR JSONB_CONTAINS($a3.keywords, :$p) = true
                                OR JSONB_CONTAINS($ae.keywords, :$p) = true";
                $qb->setParameter($p, json_encode([['k' => $kw]]));
            }
            $qb->andWhere(implode(' OR ', $orClauses));
        }
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'effectKeyword' => [
                'property'      => 'effectKeyword',
                'type'          => 'string',
                'required'      => false,
                'is_collection' => true,
                'description'   => 'Filter by keyword(s). Use effectKeywordMode=and|or (default or).',
            ],
            'effectKeywordMode' => [
                'property'    => 'effectKeywordMode',
                'type'        => 'string',
                'required'    => false,
                'description' => 'Keyword combination mode: "and" or "or" (default)',
            ],
        ];
    }
}
