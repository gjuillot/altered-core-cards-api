<?php

namespace App\Controller;

use App\Entity\AbilityCondition;
use App\Entity\AbilityEffect;
use App\Entity\AbilityTrigger;
use App\Entity\CardSubType;
use App\Entity\CardType;
use App\Entity\Faction;
use App\Entity\Rarity;
use App\Entity\Set;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/query', name: 'query', methods: ['GET'])]
class QueryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(): Response
    {
        $factions   = $this->em->getRepository(Faction::class)->findBy([], ['position' => 'ASC']);
        $sets       = $this->em->getRepository(Set::class)->findBy([], ['name' => 'ASC']);
        $cardTypes  = $this->em->getRepository(CardType::class)->findAll();
        $subTypes   = $this->em->getRepository(CardSubType::class)->findAll();
        $rarities   = $this->em->getRepository(Rarity::class)->findBy([], ['position' => 'ASC']);
        $triggers   = $this->em->getRepository(AbilityTrigger::class)->findBy([], ['id' => 'ASC']);
        $conditions = $this->em->getRepository(AbilityCondition::class)->findBy([], ['id' => 'ASC']);
        $effects    = $this->em->getRepository(AbilityEffect::class)->findBy([], ['id' => 'ASC']);

        return $this->render('query/index.html.twig', [
            'factions'   => $factions,
            'sets'       => $sets,
            'cardTypes'  => $cardTypes,
            'subTypes'   => $subTypes,
            'rarities'   => $rarities,
            'triggers'   => $triggers,
            'conditions' => $conditions,
            'effects'    => $effects,
        ]);
    }
}
