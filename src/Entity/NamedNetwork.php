<?php

namespace App\Entity;

use App\Repository\NamedNetworkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A known WiFi network, named for whatever room/context it identifies
 * (e.g. "Bedroom", "Car", "Living Room") — finer-grained than
 * NamedLocation's single wifiSsid-per-place, for networks worth
 * recognizing on their own rather than as a place-level geofence.
 */
#[ORM\Entity(repositoryClass: NamedNetworkRepository::class)]
class NamedNetwork
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $ssid = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSsid(): ?string
    {
        return $this->ssid;
    }

    public function setSsid(string $ssid): static
    {
        $this->ssid = $ssid;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }
}
