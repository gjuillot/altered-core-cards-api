<?php

namespace App\Entity;

use App\Model\TimestampInterface;
use App\Model\TimestampTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;

#[ORM\Entity(repositoryClass: TranslationRepository::class)]
#[ORM\Index(name: "idx_card_translation_card_id", fields: ["card"])]
class CardTranslation implements TimestampInterface
{
    use TimestampTrait;

    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    #[ORM\Column(type: Types::STRING, length: 8)]
    private $locale;

    #[ORM\Column(length: 50, nullable: false)]
    private string $name;

    #[ORM\Column(type: "json", nullable: false)]
    private array $elements = [];

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $echoEffect = null;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $mainEffect = null;

    #[ORM\ManyToOne(targetEntity: Card::class, inversedBy: 'translations')]
    protected Card $card;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $collectorNumberFormatedId = null;

    #[ORM\Column(nullable: true)]
    private ?string $imgPath = null;

    #[ORM\Column(nullable: true)]
    private ?string $downloadedImgPath = null;

    public function __construct()
    {
        $this->creationDate = new \DateTimeImmutable();
    }

    /**
     * @return mixed
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     * @param mixed $locale
     */
    public function setLocale($locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getElements(): array
    {
        return $this->elements;
    }

    public function setElements(array $elements): self
    {
        $this->elements = $elements;

        return $this;
    }

    public function getEchoEffect(): ?string
    {
        return $this->echoEffect;
    }

    public function setEchoEffect(?string $echoEffect): self
    {
        $this->echoEffect = $echoEffect;

        return $this;
    }

    public function getMainEffect(): ?string
    {
        return $this->mainEffect;
    }

    public function setMainEffect(?string $mainEffect): self
    {
        $this->mainEffect = $mainEffect;

        return $this;
    }

    public function getCard(): Card
    {
        return $this->card;
    }

    public function setCard(Card $card): self
    {
        $this->card = $card;

        return $this;
    }

    public function setImgPath(?string $imgPath): self
    {
        $this->imgPath = $imgPath;

        return $this;
    }

    public function getImgPath(): ?string
    {
        return $this->imgPath;
    }

    public function getCollectorNumberFormatedId(): ?string
    {
        return $this->collectorNumberFormatedId;
    }

    public function setCollectorNumberFormatedId(?string $collectorNumberFormatedId): self
    {
        $this->collectorNumberFormatedId = $collectorNumberFormatedId;

        return $this;
    }

    public function getDownloadedImgPath(): ?string
    {
        return $this->downloadedImgPath;
    }

    public function setDownloadedImgPath(?string $downloadedImgPath): self
    {
        $this->downloadedImgPath = $downloadedImgPath;

        return $this;
    }
}