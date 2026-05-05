<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Model\TimestampInterface;
use App\Model\TimestampTrait;
use App\Repository\FactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: FactionRepository::class)]
#[ORM\Index(name: 'idx_faction_code', fields: ['code'])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    normalizationContext: ['groups' => ['faction:read']],
    paginationEnabled: false,
)]
class Faction implements TimestampInterface
{
    use TimestampTrait;

    public const FACTIONS = [
        'BR' => 'Bravos',
        'AX' => 'Axiom',
        'LY' => 'Lyra',
        'MU' => 'Muna',
        'OR' => 'Ordis',
        'YZ' => 'Yzmir'
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['card:list', 'card:read', 'faction:read', 'card_group:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: false)]
    #[Groups(['card:list', 'card:read', 'faction:read', 'card_group:read'])]
    private string $name;

    #[ORM\Column(length: 5, nullable: false)]
    #[Groups(['card:list', 'card:read', 'faction:read', 'card_group:read'])]
    private string $code;

    #[ORM\Column(type: 'integer')]
    #[Groups(['card:list', 'card:read', 'faction:read', 'card_group:read'])]
    private int $position;

    public function __construct()
    {
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getCode(): string { return $this->code; }
    public function setCode(?string $code): self { $this->code = $code; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
