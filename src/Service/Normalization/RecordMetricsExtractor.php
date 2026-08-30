<?php

namespace App\Service\Normalization;

use App\Entity\NamedLocation;
use App\Entity\Record;
use App\Enum\RecordType;
use App\Repository\NamedLocationRepository;

/**
 * Populates Record's denormalized metric columns from payload_json —
 * payload stays the source of truth, these are a derived read cache.
 * Recomputed from payload every time, never hand-edited; safe to call
 * repeatedly (idempotent) or drop and regenerate.
 *
 * Only Steps, HeartRate, and LocationFix are handled so far (see
 * CLAUDE.md's open-type contract — 'type' is an open set, and this
 * extractor mirrors that: anything else just gets nulled out here rather
 * than rejected). Add a case to extract() when another type's fields are
 * worth caching, and check that type's real payload shape first — don't
 * assume it looks like any of these (see the per-type-consolidation-
 * semantics lesson: Steps is a scalar daily count, HeartRate is a sample
 * array, LocationFix is a single lat/lon fix, and a fourth type could
 * easily be none of those).
 */
final class RecordMetricsExtractor
{
    /** @var NamedLocation[]|null loaded once per instance (one HTTP request or console run), not per record */
    private ?array $namedLocations = null;

    public function __construct(
        private readonly NamedLocationRepository $namedLocationRepository,
    ) {
    }

    public function extract(Record $record): void
    {
        $payload = $record->getPayload();

        $dataOrigin = $payload['dataOrigin'] ?? null;
        $record->setDataOrigin(is_string($dataOrigin) ? $dataOrigin : null);

        $this->clearMetrics($record);
        $this->clearLocation($record);

        match (RecordType::canonicalize($record->getType())) {
            'Steps' => $this->extractSteps($record, $payload),
            'HeartRate' => $this->extractHeartRate($record, $payload),
            'LocationFix' => $this->extractLocation($record, $payload),
            default => null,
        };
    }

    /**
     * Steps payload is one scalar daily/bucket count, not a sample array —
     * see docs/02-data-model.md. metricMin/Max would just duplicate
     * metricValue, so they're left null rather than filled in redundantly.
     */
    private function extractSteps(Record $record, array $payload): void
    {
        $count = $payload['count'] ?? null;
        if (!is_numeric($count)) {
            return;
        }

        $record->setMetricValue((float) $count)->setMetricMin(null)->setMetricMax(null)->setSampleCount(1);
    }

    /**
     * HeartRate payload is a samples array of {time, beatsPerMinute} — see
     * docs/02-data-model.md. metricValue is the row's average bpm;
     * metricMin/Max/sampleCount preserve enough of the distribution that
     * chart/summary code doesn't need to re-decode payload just to know
     * the row wasn't a single flat reading.
     */
    private function extractHeartRate(Record $record, array $payload): void
    {
        $samples = is_array($payload['samples'] ?? null) ? $payload['samples'] : [];

        $bpmValues = [];
        foreach ($samples as $sample) {
            $bpm = is_array($sample) ? ($sample['beatsPerMinute'] ?? null) : null;
            if (is_numeric($bpm)) {
                $bpmValues[] = (float) $bpm;
            }
        }

        if ($bpmValues === []) {
            return;
        }

        $record
            ->setMetricValue(round(array_sum($bpmValues) / count($bpmValues), 1))
            ->setMetricMin(min($bpmValues))
            ->setMetricMax(max($bpmValues))
            ->setSampleCount(count($bpmValues));
    }

    /**
     * LocationFix payload is a single lat/lon fix, not a series — see
     * docs/02-data-model.md. accuracyMeters is cached alongside it since
     * GPS-drift filtering (e.g. the 500m geofence in the retired
     * location-context tool) needs it without re-decoding payload.
     * closestToId is the same nearest-place lookup CurrentStateResolver
     * uses for its "closest to" note (NamedLocationRepository::
     * findClosestAmong(), radiusMeters ignored), cached so the admin list
     * can filter by named place without recomputing haversine per row.
     */
    private function extractLocation(Record $record, array $payload): void
    {
        $latitude = $payload['latitude'] ?? null;
        $longitude = $payload['longitude'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        $accuracy = $payload['accuracyMeters'] ?? null;
        $closest = $this->namedLocationRepository->findClosestAmong($this->loadNamedLocations(), $latitude, $longitude);

        $record
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setAccuracyMeters(is_numeric($accuracy) ? (float) $accuracy : null)
            ->setClosestToId($closest?->getId());
    }

    private function clearMetrics(Record $record): void
    {
        $record->setMetricValue(null)->setMetricMin(null)->setMetricMax(null)->setSampleCount(null);
    }

    private function clearLocation(Record $record): void
    {
        $record->setLatitude(null)->setLongitude(null)->setAccuracyMeters(null)->setClosestToId(null);
    }

    /**
     * @return NamedLocation[]
     */
    private function loadNamedLocations(): array
    {
        return $this->namedLocations ??= $this->namedLocationRepository->findAll();
    }
}
