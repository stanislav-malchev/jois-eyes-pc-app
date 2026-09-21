<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'finance_receipts')]
class Receipt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Transaction $transaction = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $merchant = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $totalBgn = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $taxBgn = null;

    #[ORM\Column(length: 10)]
    private string $currency = 'BGN';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ocrSource = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ocrRawText = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ocrModel = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 4, nullable: true)]
    private ?string $ocrConfidence = null;

    #[ORM\Column(length: 50)]
    private string $status = 'unmatched';

    #[ORM\Column(type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $importedAt = null;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(?Transaction $transaction): self
    {
        $this->transaction = $transaction;
        return $this;
    }

    public function getMerchant(): ?string
    {
        return $this->merchant;
    }

    public function setMerchant(?string $merchant): self
    {
        $this->merchant = $merchant;
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

    public function getTotalBgn(): ?string
    {
        return $this->totalBgn;
    }

    public function setTotalBgn(?string $totalBgn): self
    {
        $this->totalBgn = $totalBgn;
        return $this;
    }

    public function getTaxBgn(): ?string
    {
        return $this->taxBgn;
    }

    public function setTaxBgn(?string $taxBgn): self
    {
        $this->taxBgn = $taxBgn;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function getOcrSource(): ?string
    {
        return $this->ocrSource;
    }

    public function setOcrSource(?string $ocrSource): self
    {
        $this->ocrSource = $ocrSource;
        return $this;
    }

    public function getOcrRawText(): ?string
    {
        return $this->ocrRawText;
    }

    public function setOcrRawText(?string $ocrRawText): self
    {
        $this->ocrRawText = $ocrRawText;
        return $this;
    }

    public function getOcrModel(): ?string
    {
        return $this->ocrModel;
    }

    public function setOcrModel(?string $ocrModel): self
    {
        $this->ocrModel = $ocrModel;
        return $this;
    }

    public function getOcrConfidence(): ?string
    {
        return $this->ocrConfidence;
    }

    public function setOcrConfidence(?string $ocrConfidence): self
    {
        $this->ocrConfidence = $ocrConfidence;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
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

    public function __toString(): string
    {
        return $this->merchant ?? ($this->id?->toRfc4122() ?? '');
    }
}
