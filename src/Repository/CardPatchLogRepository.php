<?php

namespace App\Repository;

use App\Entity\CardPatchLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardPatchLog>
 */
class CardPatchLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardPatchLog::class);
    }

    public function findByFilename(string $filename): ?CardPatchLog
    {
        return $this->findOneBy(['filename' => $filename]);
    }
}
