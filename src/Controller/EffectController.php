<?php

namespace App\Controller;

use App\Repository\AbilityEffectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class EffectController extends AbstractController
{
    public function __construct(
        private readonly AbilityEffectRepository $repository,
    ) {}

    #[Route('/api/effects', name: 'api_effects', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $result = [];

        $factionsByAlteredId = $this->repository->findFactionCodesByAlteredId();

        foreach ($this->repository->findBy([], ['alteredId' => 'ASC']) as $effect) {
            $result[] = [
                'alteredId'     => $effect->getAlteredId(),
                'filterExample' => 'effectSlot[1][effect]=' . $effect->getAlteredId(),
                'isSupport'     => $effect->isSupport(),
                'factions'      => $factionsByAlteredId[$effect->getAlteredId()] ?? [],
                'translations'  => $effect->getText(),
            ];
        }

        return $this->json($result);
    }
}
