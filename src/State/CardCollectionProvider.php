<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Card;
use App\Entity\CardGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wraps CachedCountCollectionProvider and batch-loads all associations
 * needed for card:list serialization, eliminating N+1 queries.
 *
 * Strategy:
 *  1. Let the inner provider run the paginated query (1 SQL).
 *  2. Collect the card IDs from the page.
 *  3. Run one DQL query with LEFT JOINs for every association used during
 *     serialization, scoped to those IDs. Doctrine's identity map ensures
 *     the same entity instances are updated in-place.
 *  4. Return the original paginator — the serializer finds everything loaded.
 */
final class CardCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly CachedCountCollectionProvider $inner,
        private readonly EntityManagerInterface $em,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->inner->provide($operation, $uriVariables, $context);

        if (!$result instanceof \Traversable) {
            return $result;
        }

        $ids = [];
        foreach ($result as $item) {
            if ($item instanceof Card) {
                $ids[] = $item->getId();
            }
        }

        if (empty($ids)) {
            return $result;
        }

        $bga = ($context['filters']['bga'] ?? false);

        // Query 1 — ManyToOne associations (no Cartesian product risk)
        // Always join effects for BGA mode (must load before normalization)
        $select = $bga
            ? 'c, s, cg, cgf, cgr, cgct, cgchs, e1, e1t, e1c, e1e, e2, e2t, e2c, e2e, e3, e3t, e3c, e3e'
            : 'c, s, cg, cgf, cgr, cgct, cgchs';

        $qb = $this->em->createQueryBuilder()
            ->select($select)
            ->from(Card::class, 'c')
            ->leftJoin('c.set', 's')
            ->leftJoin('c.cardGroup', 'cg')
            ->leftJoin('cg.faction', 'cgf')
            ->leftJoin('cg.rarity', 'cgr')
            ->leftJoin('cg.cardType', 'cgct')
            ->leftJoin('cg.cardHistoryStatus', 'cgchs');

        if ($bga) {
            $qb->leftJoin('cg.effect1', 'e1')
                ->leftJoin('e1.abilityTrigger', 'e1t')
                ->leftJoin('e1.abilityCondition', 'e1c')
                ->leftJoin('e1.abilityEffect', 'e1e')
                ->leftJoin('cg.effect2', 'e2')
                ->leftJoin('e2.abilityTrigger', 'e2t')
                ->leftJoin('e2.abilityCondition', 'e2c')
                ->leftJoin('e2.abilityEffect', 'e2e')
                ->leftJoin('cg.effect3', 'e3')
                ->leftJoin('e3.abilityTrigger', 'e3t')
                ->leftJoin('e3.abilityCondition', 'e3c')
                ->leftJoin('e3.abilityEffect', 'e3e');
        }

        $qb->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Query 2 — translations (OneToMany — no longer EAGER on entity)
        $this->em->createQueryBuilder()
            ->select('c, t')
            ->from(Card::class, 'c')
            ->leftJoin('c.translations', 't')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Query 3 — artists (ManyToMany) — separate query to avoid Cartesian product
        $this->em->createQueryBuilder()
            ->select('c, a')
            ->from(Card::class, 'c')
            ->leftJoin('c.artists', 'a')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
