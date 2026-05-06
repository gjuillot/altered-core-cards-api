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
}
