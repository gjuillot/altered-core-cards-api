<?php

namespace App\Controller;

use App\Repository\AbilityTriggerRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class TriggerController extends AbstractController
{
    public function __construct(
        private readonly AbilityTriggerRepository $repository,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[Route('/api/triggers', name: 'api_triggers', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $item = $this->cache->getItem('api.catalog.triggers');

        if ($item->isHit()) {
            return $this->json($item->get());
        }

        $factionsByAlteredId = $this->repository->findFactionCodesByAlteredId();
        $result = [];

        foreach ($this->repository->findBy([], ['alteredId' => 'ASC']) as $trigger) {
            $result[] = [
                'alteredId'     => $trigger->getAlteredId(),
                'filterExample' => 'effectTriggerType=' . $trigger->getAlteredId(),
                'isSupport'     => $trigger->isSupport(),
                'factions'      => $factionsByAlteredId[$trigger->getAlteredId()] ?? [],
                'translations'  => $trigger->getText(),
            ];
        }

        $item->set($result)->expiresAfter(null);
        $this->cache->save($item);

        return $this->json($result);
    }
}
