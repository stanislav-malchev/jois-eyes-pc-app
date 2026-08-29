<?php

namespace App\Service\Consolidation;

use App\Entity\Record;

/**
 * Same canonical type + identical start_time (e.g. an /v1/ingest retry
 * re-posting the same record under a fresh recordUid) — keep the richer
 * payload, tie-break by lowest id. Shared by ConsolidationEngine (generic
 * overlap handling) and DailyStepsMerger (Steps' own daily-merge rule),
 * since both need to drop retry duplicates before doing anything else.
 */
final class ExactDuplicates
{
    /**
     * @param Record[] $records
     * @return int[] ids of the duplicate rows to drop
     */
    public static function ids(array $records): array
    {
        $byStartTime = [];
        foreach ($records as $record) {
            $byStartTime[$record->getStartTime()->format(\DateTimeInterface::ATOM)][] = $record;
        }

        $duplicateIds = [];
        foreach ($byStartTime as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, static fn (Record $a, Record $b) => self::richness($b) <=> self::richness($a) ?: $a->getId() <=> $b->getId());
            array_shift($group); // keep the richest / lowest-id row
            foreach ($group as $loser) {
                $duplicateIds[] = $loser->getId();
            }
        }

        return $duplicateIds;
    }

    private static function richness(Record $record): int
    {
        return count(array_filter($record->getPayload(), static fn ($v) => $v !== null && $v !== '' && $v !== []));
    }
}
