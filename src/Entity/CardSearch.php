<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @internal
 *
 * Flat denormalized table for fast effect/keyword filtering on Card.
 * Managed exclusively by CardSearchUpdater via raw DBAL — never persist,
 * update, or remove this entity through the ORM.
 *
 * Schema is intentionally not mapped to an API resource.
 * To rebuild: php bin/console app:build-card-search
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'card_search')]
class CardSearch
{
    #[ORM\Id]
    #[ORM\Column(name: 'card_id')]
    #[ORM\OneToOne(targetEntity: Card::class)]
    #[ORM\JoinColumn(name: 'card_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private int $cardId;

    #[ORM\Column(nullable: true)]
    private ?int $t1 = null;

    #[ORM\Column(nullable: true)]
    private ?int $c1 = null;

    #[ORM\Column(nullable: true)]
    private ?int $e1 = null;

    #[ORM\Column(nullable: true)]
    private ?int $t2 = null;

    #[ORM\Column(nullable: true)]
    private ?int $c2 = null;

    #[ORM\Column(nullable: true)]
    private ?int $e2 = null;

    #[ORM\Column(nullable: true)]
    private ?int $t3 = null;

    #[ORM\Column(nullable: true)]
    private ?int $c3 = null;

    #[ORM\Column(nullable: true)]
    private ?int $e3 = null;

    #[ORM\Column(name: 'has_effect', options: ['default' => false])]
    private bool $hasEffect = false;

    #[ORM\Column(name: 'is_public', options: ['default' => false])]
    private bool $isPublic = false;

    /** PostgreSQL TEXT[] — managed via DBAL, not ORM. */
    #[ORM\Column(columnDefinition: "TEXT[] NOT NULL DEFAULT '{}'")]
    private array $keywords = [];

    public function getCardId(): int { return $this->cardId; }
    public function getT1(): ?int { return $this->t1; }
    public function getC1(): ?int { return $this->c1; }
    public function getE1(): ?int { return $this->e1; }
    public function getT2(): ?int { return $this->t2; }
    public function getC2(): ?int { return $this->c2; }
    public function getE2(): ?int { return $this->e2; }
    public function getT3(): ?int { return $this->t3; }
    public function getC3(): ?int { return $this->c3; }
    public function getE3(): ?int { return $this->e3; }
    public function isHasEffect(): bool { return $this->hasEffect; }
    public function isPublic(): bool { return $this->isPublic; }
    public function getKeywords(): array { return $this->keywords; }
}
