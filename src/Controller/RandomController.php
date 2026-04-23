<?php

namespace App\Controller;

use App\Entity\Faction;
use App\Entity\Rarity;
use App\Entity\Set;
use App\Filter\RandomCardFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/random', name: 'random', methods: ['GET'])]
final class RandomController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(): Response
    {
        $factions = $this->em->getRepository(Faction::class)->findBy([], ['position' => 'ASC']);
        $sets = $this->em->getRepository(Set::class)->findBy([], ['name' => 'ASC']);
        $rarities = $this->em->getRepository(Rarity::class)->findBy([], ['position' => 'ASC']);

        $baseUrl = $this->urlGenerator->generate('api_cards_collection', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('random/index.html.twig', [
            'factions' => $factions,
            'sets' => $sets,
            'rarities' => $rarities,
            'baseUrl' => $baseUrl,
        ]);
    }
}
