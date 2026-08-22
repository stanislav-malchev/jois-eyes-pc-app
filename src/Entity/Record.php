<?php

namespace App\Entity;

use App\Repository\RecordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecordRepository::class)]
#[ORM\Table(name: 'records')]
#[ORM\UniqueConstraint(name: 'uniq_records_record_uid', columns: ['record_uid'])]
#[ORM\Index(name: 'idx_records_type_start_time', columns: ['type', 'start_time'])]
class Record
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $recordUid;

    #[ORM\Column(length: 32)]
    private string $source;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column]
    private \DateTimeImmutable $ingestedAt;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column]
    private bool $deleted = false;

    #[ORM\Column]
    private array $payload = [];

    public function __construct(string $recordUid)
    {
        $this->recordUid = $recordUid;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecordUid(): string
    {
        return $this->recordUid;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getIngestedAt(): \DateTimeImmutable
    {
        return $this->ingestedAt;
    }

    public function setIngestedAt(\DateTimeImmutable $ingestedAt): static
    {
        $this->ingestedAt = $ingestedAt;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): static
    {
        $this->deleted = $deleted;

        return $this;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Not persisted; exists so the admin detail view has a plain string to
     * show (EasyAdmin's text fields can't render an array value directly).
     */
    public function getPayloadPretty(): string
    {
        return json_encode($this->payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) ?: '';
    }
}
