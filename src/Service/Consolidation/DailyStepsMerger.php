<?php

namespace App\Service\Consolidation;

use App\Entity\Record;
use App\Repository\RecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Steps gets its own rule instead of OverlapResolver's keep-or-drop model:
 * decided 29.08.2026 with Stan that the granular per-overlap priority
 * dropping was the wrong shape for Steps specifically (it silently dropped
 * a whole day's Samsung Health rollup any time it overlapped even a single
 * Health Connect burst, discarding that day's alternate reading entirely)
 * — Steps should instead always collapse to exactly one row per calendar
 * day, still preferring Health Connect's own total when it's present.
 * OverlapResolver/ConsolidationEngine stay as they are for HeartRate and
 * other sample-array types, where keep-or-drop is still the right model.
 *
 * Per day: find the highest-priority dataOrigin tier present (via
 * DataOriginPriority — unchanged, Health Connect still wins), drop
 * same-tier retry duplicates, and sum the rest — Health Connect's own
 * bursts are genuinely additive (non-overlapping sensor readings), unlike
 * summing *across* different apps' independent measurements of the same
 * day, which is exactly the double-counting this whole effort exists to
 * avoid. Lower-tier rows for that day (e.g. Samsung Health's rollup once a
 * Health Connect reading exists) are dropped, not kept as a second row —
 * "one row per day" per Stan's instruction, not "one row per app per day".
 */
final class DailyStepsMerger
{
    private const TIMEZONE = 'Europe/Sofia';

    public function __construct(
        private readonly DataOriginPriority $priority,
        private readonly RecordRepository $records,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param Record[] $records
     * @return array<string, Record[]> Steps records for one type, bucketed by Europe/Sofia calendar day (Y-m-d)
     */
    public function groupByCalendarDay(array $records): array
    {
        $byDay = [];
        foreach ($records as $record) {
            $day = $record->getStartTime()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
            $byDay[$day][] = $record;
        }

        return $byDay;
    }

    /**
     * Pure computation, no DB writes — also what LiveVitalsResolver uses to
     * total today's steps without waiting for a batch/scheduled merge.
     *
     * @param Record[] $records Steps records for a single calendar day
     * @return array{total: int, winners: Record[], losers: Record[]}
     */
    public function resolveDay(array $records): array
    {
        if ($records === []) {
            return ['total' => 0, 'winners' => [], 'losers' => []];
        }

        $priorityOf = fn (Record $r) => $this->priority->priorityOf($r->getPayload()['dataOrigin'] ?? null);
        $maxPriority = max(array_map($priorityOf, $records));
        $tier = array_values(array_filter($records, fn (Record $r) => $priorityOf($r) === $maxPriority));
        $rest = array_values(array_filter($records, fn (Record $r) => $priorityOf($r) !== $maxPriority));

        $dupIdsInTier = ExactDuplicates::ids($tier);
        $winners = array_values(array_filter($tier, static fn (Record $r) => !in_array($r->getId(), $dupIdsInTier, true)));
        $dupLosers = array_values(array_filter($tier, static fn (Record $r) => in_array($r->getId(), $dupIdsInTier, true)));

        $total = array_sum(array_map(static fn (Record $r) => (int) ($r->getPayload()['count'] ?? 0), $winners));

        return ['total' => $total, 'winners' => $winners, 'losers' => [...$rest, ...$dupLosers]];
    }

    /**
     * Collapses one calendar day's Steps records into a single row carrying
     * the winning tier's summed count. No-op if the day is already a single
     * row with nothing to drop.
     *
     * @param Record[] $records Steps records for one calendar day
     * @return int[] ids removed (empty if nothing changed)
     */
    public function mergeDay(array $records, bool $dryRun): array
    {
        $resolved = $this->resolveDay($records);
        $winners = $resolved['winners'];
        $losers = $resolved['losers'];

        if (count($winners) <= 1 && $losers === []) {
            return [];
        }

        usort($winners, static fn (Record $a, Record $b) => $a->getId() <=> $b->getId());
        $survivorId = array_shift($winners)->getId();
        $removedIds = [
            ...array_map(static fn (Record $r) => $r->getId(), $winners),
            ...array_map(static fn (Record $r) => $r->getId(), $losers),
        ];

        if (!$dryRun) {
            $starts = array_map(static fn (Record $r) => $r->getStartTime(), $resolved['winners']);
            $ends = array_map(static fn (Record $r) => $r->getEndTime() ?? $r->getStartTime(), $resolved['winners']);
            $minStart = min($starts);
            $maxEnd = max($ends);

            $this->em->wrapInTransaction(function () use ($survivorId, $minStart, $maxEnd, $resolved, $removedIds): void {
                // Batch callers (the Phase-1 command, day by day) can leave
                // thousands of entities managed across many calendar days;
                // re-fetching by id rather than reusing the entity handed
                // in, then clear()-ing right after flush, keeps every call
                // to mergeDay() O(1) in identity-map size instead of every
                // flush() re-checking every previously-touched day's rows
                // (measured: minutes, not seconds, without this).
                $survivor = $this->records->find($survivorId);
                $payload = $survivor->getPayload();
                $payload['count'] = $resolved['total'];
                $survivor->setStartTime($minStart)->setEndTime($maxEnd)->setPayload($payload);
                $this->em->flush();
                $this->em->clear();

                $this->records->hardDeleteByIds($removedIds);
            });
        }

        return $removedIds;
    }
}
