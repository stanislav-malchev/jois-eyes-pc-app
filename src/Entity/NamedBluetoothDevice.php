<?php

namespace App\Entity;

use App\Repository\NamedBluetoothDeviceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A known paired/nearby Bluetooth device, named for whatever it identifies
 * (e.g. "Car", "Living Room Speaker") — same idea as NamedNetwork, for
 * BT-based presence/context matching alongside WiFi.
 */
#[ORM\Entity(repositoryClass: NamedBluetoothDeviceRepository::class)]
class NamedBluetoothDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deviceName = null;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $macAddress = null;

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

    public function getDeviceName(): ?string
    {
        return $this->deviceName;
    }

    public function setDeviceName(?string $deviceName): static
    {
        $this->deviceName = $deviceName;

        return $this;
    }

    public function getMacAddress(): ?string
    {
        return $this->macAddress;
    }

    public function setMacAddress(?string $macAddress): static
    {
        $this->macAddress = $macAddress;

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
