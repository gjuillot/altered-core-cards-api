<?php

namespace App\Controller;

use App\Repository\AbilityConditionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ConditionController extends AbstractController
{
    public function __construct(
        private readonly AbilityConditionRepository $repository,
    ) {}

    #[Route('/api/conditions', name: 'api_conditions', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $result = [];

        $factionsByAlteredId = $this->repository->findFactionCodesByAlteredId();

        foreach ($this->repository->findBy([], ['alteredId' => 'ASC']) as $condition) {
            $result[] = [
                'alteredId'     => $condition->getAlteredId(),
                'filterExample' => 'effectSlot[1][condition]=' . $condition->getAlteredId(),
                'isSupport'     => $condition->isSupport(),
                'factions'      => $factionsByAlteredId[$condition->getAlteredId()] ?? [],
                'translations'  => $condition->getText(),
            ];
        }

        return $this->json($result);
    }
}
