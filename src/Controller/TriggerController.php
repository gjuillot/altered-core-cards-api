<?php

namespace App\Controller;

use App\Repository\AbilityTriggerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class TriggerController extends AbstractController
{
    public function __construct(
        private readonly AbilityTriggerRepository $repository,
    ) {}

    #[Route('/api/triggers', name: 'api_triggers', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $result = [];

        $factionsByAlteredId = $this->repository->findFactionCodesByAlteredId();

        foreach ($this->repository->findBy([], ['alteredId' => 'ASC']) as $trigger) {
            $result[] = [
                'alteredId'     => $trigger->getAlteredId(),
                'filterExample' => 'effectTriggerType=' . $trigger->getAlteredId(),
                'isSupport'     => $trigger->isSupport(),
                'factions'      => $factionsByAlteredId[$trigger->getAlteredId()] ?? [],
                'translations'  => $trigger->getText(),
            ];
        }

        return $this->json($result);
    }
}
