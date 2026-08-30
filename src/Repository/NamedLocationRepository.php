<?php

namespace App\Repository;

use App\Entity\NamedLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Location\Coordinate;
use Location\Distance\Haversine;

/**
 * @extends ServiceEntityRepository<NamedLocation>
 */
class NamedLocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NamedLocation::class);
    }

    public function findClosestTo(float $lat, float $lon): ?NamedLocation
    {
        return $this->findClosestAmong($this->findAll(), $lat, $lon);
    }

    /**
     * Nearest named place to a coordinate among a given set, by
     * straight-line distance — ignores radiusMeters entirely (per Stan:
     * "disregard the radii"). Pure/no query, so a caller that already holds
     * a places list (e.g. CurrentStateResolver, resolving several
     * coordinates per snapshot) doesn't pay for a repeated findAll().
     *
     * @param NamedLocation[] $places
     */
    public function findClosestAmong(array $places, float $lat, float $lon): ?NamedLocation
    {
        if ([] === $places) {
            return null;
        }

        $current = new Coordinate($lat, $lon);
        $haversine = new Haversine();

        $closest = null;
        $closestDistance = null;
        foreach ($places as $place) {
            $distance = $haversine->getDistance($current, new Coordinate($place->getLatitude(), $place->getLongitude()));
            if (null === $closestDistance || $distance < $closestDistance) {
                $closestDistance = $distance;
                $closest = $place;
            }
        }

        return $closest;
    }
}
