<?php

namespace App\Service\Consolidation;

use App\Entity\Record;
use App\Repository\RecordRepository;

/**
 * Exact-duplicate purge + priority/subset overlap resolution for one
 * canonical type's candidate record set. Used for sample-array types
 * (HeartRate, OxygenSaturation, ...) where "keep the winning row(s), drop
 * the rest" is the right shape. Steps is handled separately by
 * DailyStepsConsolidator — same "keep the winning tier" idea, but per
 * calendar day rather than per overlap pair — see that class for why.
 *
 * "Drop" here means soft-delete (RecordRepository::markDeleted()), never a
 * hard DELETE — see that method's docblock for why (breaks the
 * idempotent-upsert-by-recordUid contract otherwise). Tombstone
 * hard-deletion isn't handled here at all — it isn't type-scoped, callers
 * do it separately (see RecordRepository::hardDeleteTombstones()), and a
 * genuine phone-issued tombstone is safe to hard-delete for a different
 * reason than why consolidation's own decisions aren't.
 *
 * Deliberately takes the candidate set as a parameter rather than fetching
 * it itself: the Phase-1 backlog command (app:consolidate:records) passes
 * every live row of a type, while the Phase-2 scheduled job
 * (ConsolidateMessageHandler) passes only a time-windowed neighborhood of
 * newly-synced rows — same rules, different scope, so the rules live here
 * once.
 */
final class ConsolidationEngine
{
    public function __construct(
        private readonly RecordRepository $records,
        private readonly OverlapResolver $overlaps,
    ) {
    }

    /**
     * @param Record[] $records candidates for a single canonical type
     * @return array{exactDuplicateIds: int[], overlapIds: int[], flagged: Record[][]}
     */
    public function consolidateBucket(array $records, bool $dryRun): array
    {
        $exactDuplicateIds = ExactDuplicates::ids($records);
        $exactDuplicateLosers = array_values(array_filter(
            $records,
            static fn (Record $r) => in_array($r->getId(), $exactDuplicateIds, true),
        ));

        $remaining = array_values(array_filter(
            $records,
            static fn (Record $r) => !in_array($r->getId(), $exactDuplicateIds, true),
        ));
        $resolution = $this->overlaps->resolve($remaining);
        $overlapIds = array_map(static fn (Record $r) => $r->getId(), $resolution['drop']);

        if (!$dryRun) {
            $this->records->markDeleted([...$exactDuplicateLosers, ...$resolution['drop']]);
        }

        return [
            'exactDuplicateIds' => $exactDuplicateIds,
            'overlapIds' => $overlapIds,
            'flagged' => $resolution['flagged'],
        ];
    }
}
