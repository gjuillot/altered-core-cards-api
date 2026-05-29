<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use App\Filter\JsonbContainsFilter;
use App\Repository\MainEffectRepository;
use App\Service\KeywordLocalizer;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Index(name: "idx_main_effect_ability_key", fields: ["abilityKey"])]
#[ORM\Index(name: "idx_main_effect_text_en", fields: ["textEn"])]
#[ORM\Index(name: "idx_main_effect_text_fr", fields: ["textFr"])]
#[ORM\Entity(repositoryClass: MainEffectRepository::class)]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    normalizationContext: ['groups' => ['main_effect:read']],
    paginationItemsPerPage: 30,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'textFr' => 'partial',
    'textEn' => 'partial',
    'textIt' => 'partial',
    'textEs' => 'partial',
    'textDe' => 'partial',
])]
#[ApiFilter(ExistsFilter::class, properties: ['keywords'])]
#[ApiFilter(JsonbContainsFilter::class, properties: ['keywords'])]
class MainEffect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['card:read', 'main_effect:read'])]
    private ?int $id = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $textFr = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $textEn = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $textIt = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $textEs = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $textDe = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['card:read', 'main_effect:read'])]
    private ?array $keywords = null;

    /**
     * Composite key: "{triggerAlteredId}_{conditionAlteredId}_{effectAlteredId}"
     * Any null part is represented as "0".
     * Example: "7_0_283"
     */
    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['card:read', 'main_effect:read'])]
    private ?string $abilityKey = null;

    #[ORM\ManyToOne(targetEntity: AbilityTrigger::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['card:read', 'main_effect:read'])]
    private ?AbilityTrigger $abilityTrigger = null;

    #[ORM\ManyToOne(targetEntity: AbilityCondition::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['card:read', 'main_effect:read'])]
    private ?AbilityCondition $abilityCondition = null;

    #[ORM\ManyToOne(targetEntity: AbilityEffect::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['card:read', 'main_effect:read'])]
    private ?AbilityEffect $abilityEffect = null;

    #[Groups(['card:read:bga'])]
    public function getConditionId(): ?int
    {
        return $this->abilityCondition?->getAlteredId();
    }

    #[Groups(['card:read:bga'])]
    public function getEffectId(): ?int
    {
        return $this->abilityEffect?->getAlteredId();
    }

    #[Groups(['card:read', 'main_effect:read', 'card:read:bga'])]
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return sprintf(
            'MainEffect[id=%s ak=%s fr=%s en=%s]',
            $this->id ?? 'null',
            $this->abilityKey ?? 'null',
            substr($this->textFr ?? 'null', 0, 40),
            substr($this->textEn ?? 'null', 0, 40),
        );
    }

    public function getTextFr(): ?string
    {
        return $this->textFr;
    }

    public function setTextFr(?string $textFr): self
    {
        $this->textFr = $textFr;

        return $this;
    }

    public function getTextEn(): ?string
    {
        return $this->textEn;
    }

    public function setTextEn(?string $textEn): self
    {
        $this->textEn = $textEn;

        return $this;
    }

    public function getTextIt(): ?string
    {
        return $this->textIt;
    }

    public function setTextIt(?string $textIt): self
    {
        $this->textIt = $textIt;

        return $this;
    }

    public function getTextEs(): ?string
    {
        return $this->textEs;
    }

    public function setTextEs(?string $textEs): self
    {
        $this->textEs = $textEs;

        return $this;
    }

    public function getTextDe(): ?string
    {
        return $this->textDe;
    }

    public function setTextDe(?string $textDe): self
    {
        $this->textDe = $textDe;

        return $this;
    }

    public function getKeywords(): ?array
    {
        return $this->keywords;
    }

    public function setKeywords(?array $keywords): self
    {
        $this->keywords = $keywords;

        return $this;
    }

    public function getTextForLocale(string $locale): ?string
    {
        return match($locale) {
            'fr' => $this->textFr,
            'en' => $this->textEn,
            'it' => $this->textIt,
            'es' => $this->textEs,
            'de' => $this->textDe,
            default => $this->textEn,
        };
    }

    public function getAbilityKey(): ?string { return $this->abilityKey; }
    public function setAbilityKey(?string $abilityKey): self { $this->abilityKey = $abilityKey; return $this; }

    public function getAbilityTrigger(): ?AbilityTrigger { return $this->abilityTrigger; }
    public function setAbilityTrigger(?AbilityTrigger $abilityTrigger): self
    {
        $this->abilityTrigger = $abilityTrigger;
        $this->recomputeAbilityKey();
        return $this;
    }

    public function getAbilityCondition(): ?AbilityCondition { return $this->abilityCondition; }
    public function setAbilityCondition(?AbilityCondition $abilityCondition): self
    {
        $this->abilityCondition = $abilityCondition;
        $this->recomputeAbilityKey();
        return $this;
    }

    public function getAbilityEffect(): ?AbilityEffect { return $this->abilityEffect; }
    public function setAbilityEffect(?AbilityEffect $abilityEffect): self
    {
        $this->abilityEffect = $abilityEffect;
        $this->recomputeAbilityKey();
        return $this;
    }

    private function recomputeAbilityKey(): void
    {
        $t = $this->abilityTrigger?->getAlteredId() ?? 0;
        $c = $this->abilityCondition?->getAlteredId() ?? 0;
        $e = $this->abilityEffect?->getAlteredId() ?? 0;

        $this->abilityKey = ($t === 0 && $c === 0 && $e === 0) ? null : "{$t}_{$c}_{$e}";
    }
}
