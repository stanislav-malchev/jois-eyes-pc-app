<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'finance_line_items')]
class LineItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Receipt::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Receipt $receipt = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $product = null;

    #[ORM\Column(type: 'text')]
    private ?string $description = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => 1])]
    private string $quantity = '1';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $unitPriceBgn = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $totalBgn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $categoryHint = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getReceipt(): ?Receipt
    {
        return $this->receipt;
    }

    public function setReceipt(?Receipt $receipt): self
    {
        $this->receipt = $receipt;
        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): self
    {
        $this->unit = $unit;
        return $this;
    }

    public function getUnitPriceBgn(): ?string
    {
        return $this->unitPriceBgn;
    }

    public function setUnitPriceBgn(?string $unitPriceBgn): self
    {
        $this->unitPriceBgn = $unitPriceBgn;
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

    public function getCategoryHint(): ?string
    {
        return $this->categoryHint;
    }

    public function setCategoryHint(?string $categoryHint): self
    {
        $this->categoryHint = $categoryHint;
        return $this;
    }
}
