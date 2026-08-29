<?php

namespace App\Entity;

use App\Repository\NamedLocationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NamedLocationRepository::class)]
class NamedLocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?float $latitude = null;

    #[ORM\Column]
    private ?float $longitude = null;

    #[ORM\Column(options: ["default" => 150])]
    private ?int $radiusMeters = 150;

    /**
     * Optional — when set, CurrentStateTool resolves this place from a
     * matching connectivity.wifi_ssid BEFORE falling back to the
     * lat/lon/radius geofence below (WiFi association is instant and
     * room-precise; GPS drifts and can be slow/absent indoors). Null until
     * populated via /admin — an empty map just means every place falls
     * through to geofence matching, which already works standalone.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $wifiSsid = null;

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

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getRadiusMeters(): ?int
    {
        return $this->radiusMeters;
    }

    public function setRadiusMeters(int $radiusMeters): static
    {
        $this->radiusMeters = $radiusMeters;

        return $this;
    }

    public function getWifiSsid(): ?string
    {
        return $this->wifiSsid;
    }

    public function setWifiSsid(?string $wifiSsid): static
    {
        $this->wifiSsid = $wifiSsid;

        return $this;
    }
}
