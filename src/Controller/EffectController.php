<?php

namespace App\Controller;

use App\Repository\AbilityEffectRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class EffectController extends AbstractController
{
    public function __construct(
        private readonly AbilityEffectRepository $repository,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[Route('/api/effects', name: 'api_effects', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $item = $this->cache->getItem('api.catalog.effects');

        if ($item->isHit()) {
            return $this->json($item->get());
        }

        $factionsByAlteredId = $this->repository->findFactionCodesByAlteredId();
        $result = [];

        foreach ($this->repository->findBy([], ['alteredId' => 'ASC']) as $effect) {
            $result[] = [
                'alteredId'     => $effect->getAlteredId(),
                'filterExample' => 'effectSlot[1][effect]=' . $effect->getAlteredId(),
                'isSupport'     => $effect->isSupport(),
                'factions'      => $factionsByAlteredId[$effect->getAlteredId()] ?? [],
                'translations'  => $effect->getText(),
            ];
        }

        $item->set($result)->expiresAfter(null);
        $this->cache->save($item);

        return $this->json($result);
    }
}
