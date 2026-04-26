<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Card;
use App\Entity\CardGroup;
use App\Entity\CardType;
use App\Entity\Faction;
use App\Entity\Rarity;
use App\Entity\Set;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    public const CARD_REFERENCE = 'ALT_COREKS_B_AX_1_C';

    public function load(ObjectManager $manager): void
    {
        $faction = (new Faction())
            ->setName('Axiom')
            ->setCode('AX')
            ->setPosition(1);
        $manager->persist($faction);

        $cardType = (new CardType())
            ->setReference('HERO')
            ->setNameFr('Héros')
            ->setNameEn('Hero');
        $manager->persist($cardType);

        $rarity = (new Rarity())
            ->setReference('COMMON')
            ->setNameFr('Commun')
            ->setNameEn('Common')
            ->setPosition(1);
        $manager->persist($rarity);

        $set = new Set();
        $set->setName('Core');
        $set->setReference('COREKS');
        $set->setAlteredId('COREKS');
        $set->setCreationDate(new \DateTimeImmutable());
        $manager->persist($set);

        $cardGroup = (new CardGroup())
            ->setSlug('axiom-hero-test')
            ->setFaction($faction)
            ->setCardType($cardType)
            ->setRarity($rarity)
            ->setMainCost(3)
            ->setRecallCost(2);
        $manager->persist($cardGroup);

        $card = (new Card())
            ->setReference(self::CARD_REFERENCE)
            ->setAlteredId(self::CARD_REFERENCE)
            ->setCardNumber(1)
            ->setCardGroup($cardGroup)
            ->setRarity($rarity)
            ->setSet($set)
            ->setKickstarter(true)
            ->setPromo(false)
            ->setIsSerialized(false)
            ->setIsOwnerless(false)
            ->setTransfuge(false)
            ->setIsParentSerialized(false);
        $manager->persist($card);

        $manager->flush();
    }
}
