<?php

namespace App\Repository;

use App\Entity\AbilityCondition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AbilityCondition>
 */
class AbilityConditionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbilityCondition::class);
    }

    public function findByAlteredId(int $alteredId): ?AbilityCondition
    {
        return $this->findOneBy(['alteredId' => $alteredId]);
    }

    /** @return array{0: AbilityCondition[], 1: int} */
    public function findFiltered(string $q, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('a');

        if ($q !== '') {
            $qb->andWhere('a.textFr LIKE :q OR a.textEn LIKE :q OR a.textDe LIKE :q OR a.textEs LIKE :q OR a.textIt LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $results = $qb->orderBy('a.alteredId', 'ASC')
                      ->setFirstResult(($page - 1) * $perPage)
                      ->setMaxResults($perPage)
                      ->getQuery()
                      ->getResult();

        return [$results, $total];
    }

    /** @return array<int, AbilityCondition> keyed by alteredId */
    public function findAllIndexedByAlteredId(): array
    {
        $result = [];
        foreach ($this->findAll() as $entity) {
            $result[$entity->getAlteredId()] = $entity;
        }
        return $result;
    }

    /** @return array<int, string[]> alteredId → distinct faction codes */
    public function findFactionCodesByAlteredId(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative('
            SELECT DISTINCT ac.altered_id, f.code
            FROM ability_condition ac
            JOIN main_effect me ON me.ability_condition_id = ac.id
            JOIN card_group cg ON (cg.effect1_id = me.id OR cg.effect2_id = me.id OR cg.effect3_id = me.id OR cg.echo_effect1_id = me.id)
            JOIN faction f ON f.id = cg.faction_id
            ORDER BY ac.altered_id, f.code
        ');

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['altered_id']][] = $row['code'];
        }
        return $result;
    }
}
