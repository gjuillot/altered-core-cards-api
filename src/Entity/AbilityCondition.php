<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\AbilityConditionRepository;
use App\Service\KeywordLocalizer;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Index(name: "idx_ability_condition_altered_id", fields: ["alteredId"])]
#[ORM\Entity(repositoryClass: AbilityConditionRepository::class)]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    normalizationContext: ['groups' => ['ability_condition:read']],
    paginationItemsPerPage: 100,
)]
class AbilityCondition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'integer', unique: true)]
    #[Groups(['ability_condition:read', 'main_effect:read', 'card:read'])]
    private int $alteredId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textFr = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textEn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textIt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textEs = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textDe = null;

    #[ORM\Column(type: 'boolean', nullable: false)]
    #[Groups(['ability_condition:read', 'main_effect:read', 'card:read'])]
    private bool $isSupport = false;

    #[Groups(['ability_condition:read', 'main_effect:read', 'card:read'])]
    public function getText(): array
    {
        return array_filter([
            'fr' => KeywordLocalizer::localize($this->textFr, 'fr'),
            'en' => KeywordLocalizer::localize($this->textEn, 'en'),
            'it' => KeywordLocalizer::localize($this->textIt, 'it'),
            'es' => KeywordLocalizer::localize($this->textEs, 'es'),
            'de' => KeywordLocalizer::localize($this->textDe, 'de'),
        ]);
    }

    public function getId(): ?int { return $this->id; }

    public function getAlteredId(): int { return $this->alteredId; }
    public function setAlteredId(int $alteredId): self { $this->alteredId = $alteredId; return $this; }

    public function getTextFr(): ?string { return $this->textFr; }
    public function setTextFr(?string $textFr): self { $this->textFr = $textFr; return $this; }

    public function getTextEn(): ?string { return $this->textEn; }
    public function setTextEn(?string $textEn): self { $this->textEn = $textEn; return $this; }

    public function getTextIt(): ?string { return $this->textIt; }
    public function setTextIt(?string $textIt): self { $this->textIt = $textIt; return $this; }

    public function getTextEs(): ?string { return $this->textEs; }
    public function setTextEs(?string $textEs): self { $this->textEs = $textEs; return $this; }

    public function getTextDe(): ?string { return $this->textDe; }
    public function setTextDe(?string $textDe): self { $this->textDe = $textDe; return $this; }

    public function isSupport(): bool { return $this->isSupport; }
    public function setIsSupport(bool $isSupport): self { $this->isSupport = $isSupport; return $this; }

    public function getTextForLocale(string $locale): ?string
    {
        return match($locale) {
            'fr', 'fr-fr' => $this->textFr,
            'en', 'en-us' => $this->textEn,
            'it', 'it-it' => $this->textIt,
            'es', 'es-es' => $this->textEs,
            'de', 'de-de' => $this->textDe,
            default       => $this->textEn,
        };
    }
}
