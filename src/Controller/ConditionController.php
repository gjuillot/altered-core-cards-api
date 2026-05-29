<?php

namespace App\Controller;

use App\Repository\AbilityConditionRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ConditionController extends AbstractController
{
    public function __construct(
        private readonly AbilityConditionRepository $repository,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[Route('/api/conditions', name: 'api_conditions', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $item = $this->cache->getItem('api.catalog.conditions');

        if ($item->isHit()) {
            return $this->json($item->get());
        }

        $factionsByAlteredId = $this->repository->findFactionCodesByAlteredId();
        $result = [];

        foreach ($this->repository->findBy([], ['alteredId' => 'ASC']) as $condition) {
            $result[] = [
                'alteredId'     => $condition->getAlteredId(),
                'filterExample' => 'effectSlot[1][condition]=' . $condition->getAlteredId(),
                'isSupport'     => $condition->isSupport(),
                'factions'      => $factionsByAlteredId[$condition->getAlteredId()] ?? [],
                'translations'  => $condition->getText(),
            ];
        }

        $item->set($result)->expiresAfter(null);
        $this->cache->save($item);

        return $this->json($result);
    }
}
