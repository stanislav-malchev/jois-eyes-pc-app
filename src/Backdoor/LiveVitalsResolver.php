<?php

namespace App\Backdoor;

use App\Enum\RecordType;
use App\Repository\RecordRepository;
use App\Service\Consolidation\DailyStepsMerger;

/**
 * Prefers a fresh phone snapshot over stored records for the handful of
 * signals both sources can answer (location, heart rate, today's steps).
 * Falls back to stored records whenever the snapshot is unreachable or
 * doesn't have the field yet — callers don't need to know which happened,
 * only that a `source` key comes back on every result.
 */
class LiveVitalsResolver
{
    public const SOURCE_PHONE_SNAPSHOT = 'phone_snapshot';
    public const SOURCE_STORED_RECORDS = 'stored_records';

    public function __construct(
        private readonly SnapshotClient $snapshot,
        private readonly RecordRepository $records,
        private readonly DailyStepsMerger $stepsMerger,
    ) {
    }

    /**
     * @return array{source: string, timestamp: \DateTimeImmutable, latitude: float, longitude: float, accuracy: float|null, altitude: float|null}|null
     */
    public function getLocation(): ?array
    {
        $location = $this->snapshot->fetch()['location'] ?? null;
        if (is_array($location) && isset($location['lat'], $location['lon'], $location['ts'])) {
            return [
                'source' => self::SOURCE_PHONE_SNAPSHOT,
                'timestamp' => self::msToDateTime($location['ts']),
                'latitude' => (float) $location['lat'],
                'longitude' => (float) $location['lon'],
                'accuracy' => isset($location['accuracy_m']) ? (float) $location['accuracy_m'] : null,
                'altitude' => isset($location['altitude_m']) ? (float) $location['altitude_m'] : null,
            ];
        }

        $record = $this->records->findLatestBySource('location');
        $payload = $record?->getPayload() ?? [];
        if (!$record || !isset($payload['latitude'], $payload['longitude'])) {
            return null;
        }

        return [
            'source' => self::SOURCE_STORED_RECORDS,
            'timestamp' => $record->getStartTime(),
            'latitude' => (float) $payload['latitude'],
            'longitude' => (float) $payload['longitude'],
            'accuracy' => isset($payload['accuracy']) ? (float) $payload['accuracy'] : null,
            'altitude' => isset($payload['altitude']) ? (float) $payload['altitude'] : null,
        ];
    }

    /**
     * @return array{source: string, timestamp: \DateTimeImmutable, bpm: int}|null
     */
    public function getHeartRate(): ?array
    {
        $band = $this->snapshot->fetch()['wearables']['band'] ?? null;
        if (is_array($band) && isset($band['hr_bpm'], $band['hr_ts'])) {
            return [
                'source' => self::SOURCE_PHONE_SNAPSHOT,
                'timestamp' => self::msToDateTime($band['hr_ts']),
                'bpm' => (int) $band['hr_bpm'],
            ];
        }

        $record = $this->records->createQueryBuilder('r')
            ->andWhere('r.type = :type')
            ->andWhere('r.deleted = false')
            ->setParameter('type', RecordType::HEART_RATE->value)
            ->orderBy('r.startTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$record) {
            return null;
        }

        $samples = $record->getPayload()['samples'] ?? [];
        $latestSample = end($samples);
        if (!$latestSample || !isset($latestSample['beatsPerMinute'])) {
            return null;
        }

        return [
            'source' => self::SOURCE_STORED_RECORDS,
            'timestamp' => isset($latestSample['time'])
                ? new \DateTimeImmutable($latestSample['time'])
                : $record->getStartTime(),
            'bpm' => (int) $latestSample['beatsPerMinute'],
        ];
    }

    /**
     * @return array{source: string, steps: int}
     */
    public function getStepsToday(\DateTimeImmutable $startOfDayUtc): array
    {
        $activity = $this->snapshot->fetch()['activity'] ?? null;
        if (is_array($activity) && isset($activity['steps_today'], $activity['steps_ts'])
            && self::msToDateTime($activity['steps_ts']) >= $startOfDayUtc) {
            return [
                'source' => self::SOURCE_PHONE_SNAPSHOT,
                'steps' => (int) $activity['steps_today'],
            ];
        }

        // Summing every live Steps row double-counts when e.g. Samsung
        // Health's own daily rollup and Health Connect's phone-sensor
        // bursts both cover the same window — DailyStepsMerger picks the
        // winning dataOrigin tier (same rule the batch job uses to
        // actually collapse the day to one row) and sums just that.
        $records = $this->records->findByTypeSince(RecordType::STEPS->value, $startOfDayUtc);

        return [
            'source' => self::SOURCE_STORED_RECORDS,
            'steps' => $this->stepsMerger->resolveDay($records)['total'],
        ];
    }

    private static function msToDateTime(int $epochMillis): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.intdiv($epochMillis, 1000));
    }
}
