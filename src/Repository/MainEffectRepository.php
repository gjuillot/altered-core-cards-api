<?php

namespace App\Repository;

use App\Entity\MainEffect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MainEffect>
 */
class MainEffectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MainEffect::class);
    }

    /**
     * @return array{0: MainEffect[], 1: int}
     */
    public function findFiltered(array $filters, int $page, int $perPage, string $sort = 'id', string $dir = 'asc'): array
    {
        $qb = $this->createQueryBuilder('e');

        $this->applyFilters($qb, $filters);

        $total = (int) (clone $qb)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $allowedSorts = ['id' => 'e.id', 'ability_key' => 'e.abilityKey'];
        $orderField   = $allowedSorts[$sort] ?? 'e.id';
        $orderDir     = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        // For ability_key: put NULLs last regardless of direction
        if ($sort === 'ability_key') {
            $qb->addSelect('CASE WHEN e.abilityKey IS NULL THEN 1 ELSE 0 END AS HIDDEN nullLast');
            $qb->orderBy('nullLast', 'ASC')->addOrderBy($orderField, $orderDir);
        } else {
            $qb->orderBy($orderField, $orderDir);
        }

        /** @var MainEffect[] $results */
        $results = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [$results, $total];
    }

    /** @return array<array{trigger_id: int, trigger_text: string|null, nb: int}> */
    public function getTriggerStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative("
            SELECT at.id AS trigger_id,
                   COALESCE(at.text_fr, at.text_en) AS trigger_text,
                   COUNT(e.id) AS nb
            FROM main_effect e
            JOIN ability_trigger at ON at.id = e.ability_trigger_id
            GROUP BY at.id, COALESCE(at.text_fr, at.text_en)
            ORDER BY nb DESC
        ");
    }

    /** @return array<array{keyword: string, nb: int}> */
    public function getKeywordStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT kw->>'k' AS keyword, COUNT(*) AS nb
             FROM main_effect, jsonb_array_elements(keywords) AS kw
             WHERE keywords IS NOT NULL
             GROUP BY keyword
             ORDER BY nb DESC"
        );
    }

    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['q'])) {
            $qb->andWhere('LOWER(e.textFr) LIKE :q')
               ->setParameter('q', '%' . strtolower($filters['q']) . '%');
        }
        if (!empty($filters['abilityTrigger'])) {
            $qb->andWhere('e.abilityTrigger = :abilityTrigger')
               ->setParameter('abilityTrigger', $filters['abilityTrigger']);
        }
        if (!empty($filters['keyword'])) {
            $qb->andWhere('JSONB_CONTAINS(e.keywords, :kw) = true')
               ->setParameter('kw', json_encode([['k' => $filters['keyword']]]));
        }
        if (!empty($filters['condition'])) {
            $qb->join('e.abilityCondition', 'ac')
               ->andWhere('LOWER(ac.textFr) LIKE :condition')
               ->setParameter('condition', '%' . strtolower($filters['condition']) . '%');
        }
        if (isset($filters['linked']) && $filters['linked'] !== '') {
            match ($filters['linked']) {
                'complete'   => $qb->andWhere('e.abilityTrigger IS NOT NULL AND e.abilityCondition IS NOT NULL AND e.abilityEffect IS NOT NULL'),
                'incomplete' => $qb->andWhere('e.abilityKey IS NOT NULL')->andWhere('e.abilityTrigger IS NULL OR e.abilityCondition IS NULL OR e.abilityEffect IS NULL'),
                'none'       => $qb->andWhere('e.abilityKey IS NULL'),
                default      => null,
            };
        }
    }

    public function findOneByTextFr(string $text): ?MainEffect
    {
        // Fetch all effects with this FR text and prefer the most complete one
        // (fewest null translations) to avoid updating an incomplete row that
        // would then collide with an already-complete one on the unique index.
        $effects = $this->findBy(['textFr' => $text]);

        if (empty($effects)) {
            return null;
        }

        usort($effects, static function (MainEffect $a, MainEffect $b): int {
            $nulls = static fn(MainEffect $e): int =>
                ($e->getTextEn() === null ? 1 : 0)
                + ($e->getTextDe() === null ? 1 : 0)
                + ($e->getTextEs() === null ? 1 : 0)
                + ($e->getTextIt() === null ? 1 : 0);

            return $nulls($a) <=> $nulls($b);
        });

        return $effects[0];
    }

    public function findOneByAbilityKey(string $key): ?MainEffect
    {
        return $this->findOneBy(['abilityKey' => $key]);
    }

    /**
     * Batch-load effects by abilityKey — used to pre-warm the builder cache.
     *
     * @param  string[]              $keys
     * @return array<string, MainEffect>
     */
    public function getByAbilityKeys(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $effects = $this->createQueryBuilder('e')
            ->where('e.abilityKey IN (:keys)')
            ->setParameter('keys', array_values(array_unique($keys)))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($effects as $effect) {
            $map[$effect->getAbilityKey()] = $effect;
        }

        return $map;
    }

    public function findOneByTextEn(string $text): ?MainEffect
    {
        return $this->findOneBy(['textEn' => $text]);
    }

    public function findOneByTextDe(string $text): ?MainEffect
    {
        return $this->findOneBy(['textDe' => $text]);
    }

    public function findOneByTextEs(string $text): ?MainEffect
    {
        return $this->findOneBy(['textEs' => $text]);
    }

    public function findOneByTextIt(string $text): ?MainEffect
    {
        return $this->findOneBy(['textIt' => $text]);
    }

    public function findByAllTexts(?string $fr, ?string $en, ?string $de, ?string $es, ?string $it): ?MainEffect
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT id FROM main_effect
             WHERE COALESCE(text_fr, '') = :fr
               AND COALESCE(text_en, '') = :en
               AND COALESCE(text_de, '') = :de
               AND COALESCE(text_es, '') = :es
               AND COALESCE(text_it, '') = :it
             LIMIT 1",
            [
                'fr' => $fr ?? '',
                'en' => $en ?? '',
                'de' => $de ?? '',
                'es' => $es ?? '',
                'it' => $it ?? '',
            ]
        );

        return $id ? $this->find((int) $id) : null;
    }
}
