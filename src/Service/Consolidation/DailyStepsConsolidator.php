<?php

namespace App\Service\Consolidation;

use App\Entity\Record;
use App\Repository\RecordRepository;

/**
 * Steps gets its own rule instead of OverlapResolver's keep-or-drop model:
 * decided 29.08.2026 with Stan that the granular per-overlap priority
 * dropping was the wrong shape for Steps specifically (it silently dropped
 * a whole day's Samsung Health rollup any time it overlapped even a single
 * Health Connect burst, discarding that day's alternate reading entirely)
 * — Steps should instead always resolve to one *logical* total per
 * calendar day, still preferring Health Connect's own total when present.
 * OverlapResolver/ConsolidationEngine stay as they are for HeartRate and
 * other sample-array types, where keep-or-drop is still the right model.
 *
 * Per day: find the highest-priority dataOrigin tier present (via
 * DataOriginPriority — unchanged, Health Connect still wins), soft-delete
 * same-tier retry duplicates and every lower-tier row, and sum the
 * survivors — Health Connect's own bursts are genuinely additive
 * (non-overlapping sensor readings), unlike summing *across* different
 * apps' independent measurements of the same day.
 *
 * Corrected again same day: this originally collapsed a day's winning-tier
 * rows into one physical row (summing into a survivor, hard-deleting the
 * rest). Both parts of that broke the ingest contract's idempotent
 * upsert-by-recordUid guarantee — a hard-deleted row's stable recordUid
 * being re-synced (e.g. after a phone app reinstall) would insert it back
 * as a "new" duplicate, and worse, if the *survivor's own* recordUid was
 * ever re-synced with its original individual payload, upsertByRecordUid
 * would silently overwrite the computed sum with that one row's original
 * (much smaller) count. Now nothing is ever merged or mutated: winning-tier
 * rows are left exactly as they are, only losing rows are soft-deleted, and
 * the day's total is computed by summing survivors at read time — the same
 * mechanism LiveVitalsResolver::getStepsToday() already relies on.
 */
final class DailyStepsConsolidator
{
    private const TIMEZONE = 'Europe/Sofia';

    public function __construct(
        private readonly DataOriginPriority $priority,
        private readonly RecordRepository $records,
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
     * total today's steps without waiting for a batch/scheduled pass.
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
     * Soft-deletes one calendar day's losing rows (lower-priority tier +
     * same-tier retry duplicates). Winning rows are never touched.
     *
     * @param Record[] $records Steps records for one calendar day
     * @return int[] ids marked deleted (empty if nothing changed)
     */
    public function consolidateDay(array $records, bool $dryRun): array
    {
        $losers = $this->resolveDay($records)['losers'];

        if (!$dryRun) {
            $this->records->markDeleted($losers);
        }

        return array_map(static fn (Record $r) => $r->getId(), $losers);
    }
}
