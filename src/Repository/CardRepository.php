<?php

namespace App\Repository;

use App\Entity\Card;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /**
     * Returns a lightweight map of alteredId => id (scalars only, no entities)
     * for a specific set of alteredIds.
     *
     * @param string[] $alteredIds
     * @return array<string, int>
     */
    public function findAlteredIdMap(array $alteredIds): array
    {
        if (empty($alteredIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.alteredId', 'c.id')
            ->where('c.alteredId IN (:ids)')
            ->setParameter('ids', $alteredIds)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['alteredId']] = $row['id'];
        }

        return $map;
    }

    /**
     * Returns a map of alteredId => Card for a specific set of alteredIds.
     * Uses a lightweight query to avoid full hydration when cards are already in cache.
     *
     * @param array<string, int> $alteredIdMap  alteredId => id (from findAlteredIdMap)
     * @return array<string, Card>
     */
    public function findByAlteredIds(array $alteredIdMap): array
    {
        if (empty($alteredIdMap)) {
            return [];
        }

        $ids = array_values($alteredIdMap);
        $cards = $this->createQueryBuilder('c')
            ->addSelect('cg', 'ct', 'f', 'r')
            ->leftJoin('c.cardGroup', 'cg')
            ->leftJoin('cg.cardType', 'ct')
            ->leftJoin('cg.faction', 'f')
            ->leftJoin('c.rarity', 'r')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($cards as $card) {
            $map[$card->getAlteredId()] = $card;
        }

        return $map;
    }

    /**
     * Returns cards indexed by reference for a set of references.
     *
     * @param string[] $references
     * @return array<string, Card>
     */
    public function findByReferences(array $references): array
    {
        if (empty($references)) {
            return [];
        }

        $cards = $this->createQueryBuilder('c')
            ->addSelect('cg', 'f', 'ct', 'r', 's')
            ->leftJoin('c.cardGroup', 'cg')
            ->leftJoin('cg.faction', 'f')
            ->leftJoin('cg.cardType', 'ct')
            ->leftJoin('c.rarity', 'r')
            ->leftJoin('c.set', 's')
            ->where('c.reference IN (:refs)')
            ->setParameter('refs', $references)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($cards as $card) {
            $map[$card->getReference()] = $card;
        }

        return $map;
    }

    /** @return string[] */
    public function findReferencesByEffect(int $effectId, int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.reference')
            ->join('c.cardGroup', 'cg')
            ->where('cg.effect1 = :id OR cg.effect2 = :id OR cg.effect3 = :id')
            ->setParameter('id', $effectId)
            ->orderBy('c.reference', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'reference');
    }

    public function countByEffect(int $effectId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.cardGroup', 'cg')
            ->where('cg.effect1 = :id OR cg.effect2 = :id OR cg.effect3 = :id')
            ->setParameter('id', $effectId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Search cards by number (reference or collector number) and/or gameplay format,
     * for the gameplay-format admin screen.
     *
     * @return array{0: Card[], 1: int}
     */
    public function findFilteredForGameplayFormatAdmin(?string $cardNumber, ?string $gameplayFormat, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.cardGroup', 'cg')
            ->leftJoin('c.set', 's')
            ->addSelect('cg', 's');

        if ($cardNumber !== null && $cardNumber !== '') {
            $qb->andWhere('c.reference LIKE :num OR c.collectorNumberFormatedId LIKE :num')
               ->setParameter('num', '%' . $cardNumber . '%');
        }

        if ($gameplayFormat !== null && $gameplayFormat !== '') {
            $qb->andWhere('cg.id IN (:gfIds)')
               ->setParameter('gfIds', $this->cardGroupIdsForGameplayFormat($gameplayFormat));
        }

        $total = (int) (clone $qb)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $results = $qb
            ->orderBy('c.reference', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [$results, $total];
    }

    /** @return int[] */
    private function cardGroupIdsForGameplayFormat(string $format): array
    {
        $escaped = str_replace("'", "''", strtoupper($format));
        $ids     = $this->getEntityManager()->getConnection()
            ->fetchFirstColumn("SELECT id FROM card_group WHERE gameplay_format @> ARRAY['$escaped']");

        return $ids ?: [0];
    }

    /**
     * Resolves card references (e.g. from a gameplay-format import file) to their CardGroup id.
     *
     * @param  string[] $references
     * @return array{0: array<string,int>, 1: string[]}  [reference => cardGroupId, unmatched references]
     */
    public function resolveCardGroupIdsByReferences(array $references): array
    {
        if (empty($references)) {
            return [[], []];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.reference AS reference', 'IDENTITY(c.cardGroup) AS cardGroupId')
            ->where('c.reference IN (:refs)')
            ->andWhere('c.cardGroup IS NOT NULL')
            ->setParameter('refs', $references)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['reference']] = (int) $row['cardGroupId'];
        }

        return [$map, array_values(array_diff($references, array_keys($map)))];
    }

    /**
     * Resolves the CardGroup ids that are NOT banned by a ban-list format definition
     * (e.g. Living Legends' excluded_sets / excluded_refs): a CardGroup is excluded as
     * soon as one of its prints belongs to an excluded set, or is itself an excluded reference.
     *
     * @param  string[] $excludedSetCodes Set.reference values (e.g. "CORE", "COREKS")
     * @param  string[] $excludedRefs     Card.reference values banned regardless of set
     * @return array{0: int[], 1: string[]}  [legal CardGroup ids, excludedRefs not found in base]
     */
    public function resolveCardGroupIdsExcluding(array $excludedSetCodes, array $excludedRefs): array
    {
        $unmatchedRefs = [];
        if (!empty($excludedRefs)) {
            $rows = $this->createQueryBuilder('c')
                ->select('c.reference AS reference')
                ->where('c.reference IN (:refs)')
                ->setParameter('refs', $excludedRefs)
                ->getQuery()
                ->getArrayResult();
            $unmatchedRefs = array_values(array_diff($excludedRefs, array_column($rows, 'reference')));
        }

        $qb = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.cardGroup) AS cardGroupId')
            ->distinct()
            ->where('c.cardGroup IS NOT NULL');

        if (!empty($excludedSetCodes)) {
            $bySetDql = sprintf(
                'SELECT 1 FROM %s cs JOIN cs.set s WHERE cs.cardGroup = c.cardGroup AND s.reference IN (:excludedSetCodes)',
                Card::class,
            );
            $qb->andWhere($qb->expr()->not($qb->expr()->exists($bySetDql)))
               ->setParameter('excludedSetCodes', $excludedSetCodes);
        }

        if (!empty($excludedRefs)) {
            $byRefDql = sprintf(
                'SELECT 1 FROM %s cr WHERE cr.cardGroup = c.cardGroup AND cr.reference IN (:excludedRefs)',
                Card::class,
            );
            $qb->andWhere($qb->expr()->not($qb->expr()->exists($byRefDql)))
               ->setParameter('excludedRefs', $excludedRefs);
        }

        $ids = array_column($qb->getQuery()->getArrayResult(), 'cardGroupId');

        return [array_values(array_unique(array_map('intval', $ids))), $unmatchedRefs];
    }

    /**
     * Fetch cards by id, with cardGroup/set eagerly joined, in the given id order
     * (used to hydrate a Meilisearch result page by primary key).
     *
     * @param  int[] $ids
     * @return Card[]
     */
    public function findByIdsWithRelations(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->leftJoin('c.cardGroup', 'cg')
            ->leftJoin('c.set', 's')
            ->addSelect('cg', 's')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($rows as $card) {
            $byId[$card->getId()] = $card;
        }

        return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
    }
}
