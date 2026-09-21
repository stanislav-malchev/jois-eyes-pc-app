<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'finance_transactions')]
#[ORM\Index(name: 'idx_finance_transactions_fingerprint', columns: ['fingerprint'])]
#[ORM\Index(name: 'idx_finance_transactions_date', columns: ['date'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Account $account = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $counterparty = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $counterpartyAccount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $transactionType = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $debitBgn = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $creditBgn = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $currency = 'EUR';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 6, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: 'string', length: 64)]
    private ?string $fingerprint = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceFile = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $importedAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $softDeleted = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $softDeleteReason = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Transaction $pairedWith = null;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;
        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): self
    {
        $this->date = $date;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getCounterparty(): ?string
    {
        return $this->counterparty;
    }

    public function setCounterparty(?string $counterparty): self
    {
        $this->counterparty = $counterparty;
        return $this;
    }

    public function getCounterpartyAccount(): ?string
    {
        return $this->counterpartyAccount;
    }

    public function setCounterpartyAccount(?string $counterpartyAccount): self
    {
        $this->counterpartyAccount = $counterpartyAccount;
        return $this;
    }

    public function getTransactionType(): ?string
    {
        return $this->transactionType;
    }

    public function setTransactionType(?string $transactionType): self
    {
        $this->transactionType = $transactionType;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;
        return $this;
    }

    public function getDebitBgn(): ?string
    {
        return $this->debitBgn;
    }

    public function setDebitBgn(?string $debitBgn): self
    {
        $this->debitBgn = $debitBgn;
        return $this;
    }

    public function getCreditBgn(): ?string
    {
        return $this->creditBgn;
    }

    public function setCreditBgn(?string $creditBgn): self
    {
        $this->creditBgn = $creditBgn;
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(?string $exchangeRate): self
    {
        $this->exchangeRate = $exchangeRate;
        return $this;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;
        return $this;
    }

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    public function setSourceFile(?string $sourceFile): self
    {
        $this->sourceFile = $sourceFile;
        return $this;
    }

    public function getImportedAt(): ?\DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(\DateTimeImmutable $importedAt): self
    {
        $this->importedAt = $importedAt;
        return $this;
    }

    public function isSoftDeleted(): bool
    {
        return $this->softDeleted;
    }

    public function setSoftDeleted(bool $softDeleted): self
    {
        $this->softDeleted = $softDeleted;
        return $this;
    }

    public function getSoftDeleteReason(): ?string
    {
        return $this->softDeleteReason;
    }

    public function setSoftDeleteReason(?string $softDeleteReason): self
    {
        $this->softDeleteReason = $softDeleteReason;
        return $this;
    }

    public function getPairedWith(): ?Transaction
    {
        return $this->pairedWith;
    }

    public function setPairedWith(?Transaction $pairedWith): self
    {
        $this->pairedWith = $pairedWith;
        return $this;
    }

    public function __toString(): string
    {
        return $this->description ?? ($this->id?->toRfc4122() ?? '');
    }
}
