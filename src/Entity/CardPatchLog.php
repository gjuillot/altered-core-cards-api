<?php

namespace App\Entity;

use App\Repository\CardPatchLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CardPatchLogRepository::class)]
class CardPatchLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $filename;

    #[ORM\Column(length: 64)]
    private string $checksum;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $appliedAt;

    #[ORM\Column]
    private int $rowsUpdated = 0;

    #[ORM\Column]
    private int $rowsSkipped = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function setChecksum(string $checksum): self
    {
        $this->checksum = $checksum;

        return $this;
    }

    public function getAppliedAt(): \DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(\DateTimeImmutable $appliedAt): self
    {
        $this->appliedAt = $appliedAt;

        return $this;
    }

    public function getRowsUpdated(): int
    {
        return $this->rowsUpdated;
    }

    public function setRowsUpdated(int $rowsUpdated): self
    {
        $this->rowsUpdated = $rowsUpdated;

        return $this;
    }

    public function getRowsSkipped(): int
    {
        return $this->rowsSkipped;
    }

    public function setRowsSkipped(int $rowsSkipped): self
    {
        $this->rowsSkipped = $rowsSkipped;

        return $this;
    }
}
