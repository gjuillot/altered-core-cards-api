<?php

namespace App\EventListener;

use App\Entity\Card;
use App\Entity\CardGroup;
use App\Entity\CardGroupTranslation;
use App\Search\SearchBackendInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::postPersist, entity: Card::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Card::class)]
#[AsEntityListener(event: Events::preRemove, entity: Card::class)]
#[AsEntityListener(event: Events::postUpdate, entity: CardGroup::class)]
#[AsEntityListener(event: Events::postPersist, entity: CardGroupTranslation::class, method: 'postPersistTranslation')]
#[AsEntityListener(event: Events::postUpdate, entity: CardGroupTranslation::class)]
final class MeilisearchSyncListener
{
    /** @var bool Disable indexing during bulk imports */
    public static bool $disabled = false;

    public function __construct(private readonly SearchBackendInterface $search) {}

    public function postPersist(Card $card, PostPersistEventArgs $args): void
    {
        if (self::$disabled) return;
        $this->search->indexCard($card);
    }

    public function postPersistTranslation(CardGroupTranslation $translation, PostPersistEventArgs $args): void
    {
        if (self::$disabled) return;
        foreach ($translation->getCardGroup()->getCards() as $card) {
            $this->search->indexCard($card);
        }
    }

    public function postUpdate(Card|CardGroup|CardGroupTranslation $entity, PostUpdateEventArgs $args): void
    {
        if (self::$disabled) return;
        if ($entity instanceof Card) {
            $this->search->indexCard($entity);
            return;
        }

        $group = $entity instanceof CardGroup ? $entity : $entity->getCardGroup();
        foreach ($group->getCards() as $card) {
            $this->search->indexCard($card);
        }
    }

    public function preRemove(Card $card, PreRemoveEventArgs $args): void
    {
        if (self::$disabled) return;
        $this->search->deleteCard($card);
    }
}
