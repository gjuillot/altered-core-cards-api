<?php

namespace App\Controller;

use App\Builder\CardBuilder;
use App\Entity\Card;
use App\Repository\CardRepository;
use App\Service\CardScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final class ScrapeCardController extends AbstractController
{
    public function __construct(
        private readonly CardScraperService $cardScraper,
        private readonly EntityManagerInterface $em,
        private readonly CardRepository $cardRepository,
        private readonly CardBuilder $cardBuilder,
        private readonly SerializerInterface $serializer,
        #[Autowire(service: 'cache.card_counts')]
        private readonly CacheItemPoolInterface $cardCountsCache,
    ) {}

    #[Route('/api/cards/scrape/{reference}', name: 'card_scrape', methods: ['GET'])]
    public function __invoke(Request $request, string $reference): JsonResponse
    {
        $reference = strtoupper($reference);

        try {
            $data = $this->cardScraper->scrape($reference);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        $card = $this->cardRepository->findOneBy(['alteredId' => $data['id']]) ?? new Card();
        $card = $this->cardBuilder->build($card, $data, 'en-us');

        foreach ($data['translations'] as $locale => $translationData) {
            $card = $this->cardBuilder->build($card, $translationData, $locale);
        }

        $this->em->persist($card->getCardGroup());
        $this->em->persist($card);
        $this->cardBuilder->reconcileNewEffects($this->em);
        $this->em->flush();
        $this->cardCountsCache->clear();

        $locale  = $request->query->get('locale');
        $context = ['groups' => ['card:read']];
        if ($locale) {
            $context['locale'] = explode('-', $locale)[0];
        }

        return new JsonResponse(
            json_decode($this->serializer->serialize($card, 'json', $context), true),
            Response::HTTP_CREATED,
        );
    }
}
