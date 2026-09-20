<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'finance_products')]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Category $category = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $defaultUnit = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $typicalPriceBgn = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, options: ['default' => 0])]
    private string $lifetimeQuantity = '0';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => 0])]
    private string $lifetimeSpendBgn = '0';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tags = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstSeen = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSeen = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getDefaultUnit(): ?string
    {
        return $this->defaultUnit;
    }

    public function setDefaultUnit(?string $defaultUnit): self
    {
        $this->defaultUnit = $defaultUnit;
        return $this;
    }

    public function getTypicalPriceBgn(): ?string
    {
        return $this->typicalPriceBgn;
    }

    public function setTypicalPriceBgn(?string $typicalPriceBgn): self
    {
        $this->typicalPriceBgn = $typicalPriceBgn;
        return $this;
    }

    public function getLifetimeQuantity(): string
    {
        return $this->lifetimeQuantity;
    }

    public function setLifetimeQuantity(string $lifetimeQuantity): self
    {
        $this->lifetimeQuantity = $lifetimeQuantity;
        return $this;
    }

    public function getLifetimeSpendBgn(): string
    {
        return $this->lifetimeSpendBgn;
    }

    public function setLifetimeSpendBgn(string $lifetimeSpendBgn): self
    {
        $this->lifetimeSpendBgn = $lifetimeSpendBgn;
        return $this;
    }

    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function setTags(?array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function getFirstSeen(): ?\DateTimeImmutable
    {
        return $this->firstSeen;
    }

    public function setFirstSeen(?\DateTimeImmutable $firstSeen): self
    {
        $this->firstSeen = $firstSeen;
        return $this;
    }

    public function getLastSeen(): ?\DateTimeImmutable
    {
        return $this->lastSeen;
    }

    public function setLastSeen(?\DateTimeImmutable $lastSeen): self
    {
        $this->lastSeen = $lastSeen;
        return $this;
    }
}
