<?php

namespace App\Controller;

use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatsController extends AbstractController
{
    public function __construct(private readonly StatsService $statsService) {}

    #[Route('/stats', name: 'stats')]
    public function index(): Response
    {
        $data = $this->statsService->getPageData();

        if ($data === null) {
            return new Response(
                'Stats are being computed. Run <code>php bin/console app:stats:warmup</code> to populate.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $this->render('stats/index.html.twig', [
            'sets'           => $data['sets'],
            'globalRarities' => $data['globalRarities'],
        ]);
    }
}
