<?php

namespace App\Service\Consolidation;

use App\Entity\Record;

/**
 * Decides which of a set of same-canonical-type records to keep when their
 * time windows overlap, without ever summing/averaging scalar values —
 * confirmed 28.08.2026 that overlapping Steps records from different apps
 * can be independent measurements whose counts don't even match, so
 * "arithmetic merge" is unsafe. Two rules only, applied per overlap group:
 *
 *   1. Priority: if one record's dataOrigin outranks another's
 *      (DataOriginPriority), the lower-priority record is dropped whenever
 *      it directly overlaps a higher-priority one.
 *   2. Subset: among records tied at the top priority (typically same
 *      dataOrigin), if one's sample array is a literal subset of another's,
 *      the subset is dropped as redundant.
 *
 * Anything left conflicting after both rules (equal priority, not a subset
 * of each other — e.g. two different apps neither of which is Health
 * Connect) is kept and reported as "flagged", never auto-dropped.
 *
 * Shared by the one-off/scheduled consolidation job (decides what to hard
 * delete) and LiveVitalsResolver (decides, read-only, what to sum for
 * today) — one rule set, so a live query and a maintenance pass never
 * disagree about which record wins.
 */
final class OverlapResolver
{
    public function __construct(private readonly DataOriginPriority $priority)
    {
    }

    /**
     * @param Record[] $records records of the same canonical type, any order
     * @return array{keep: Record[], drop: Record[], flagged: Record[][]}
     */
    public function resolve(array $records): array
    {
        usort($records, static fn (Record $a, Record $b) => $a->getStartTime() <=> $b->getStartTime());

        $keep = [];
        $drop = [];
        $flagged = [];

        foreach ($this->groupOverlapping($records) as $group) {
            if (count($group) === 1) {
                $keep[] = $group[0];
                continue;
            }

            $priorityOf = fn (Record $r) => $this->priority->priorityOf($r->getPayload()['dataOrigin'] ?? null);
            $maxPriority = max(array_map($priorityOf, $group));
            $winners = array_values(array_filter($group, fn (Record $r) => $priorityOf($r) === $maxPriority));
            $contenders = array_values(array_filter($group, fn (Record $r) => $priorityOf($r) < $maxPriority));

            foreach ($contenders as $record) {
                if ($this->overlapsAny($record, $winners)) {
                    $drop[] = $record;
                } else {
                    $keep[] = $record;
                }
            }

            if (count($winners) === 1) {
                $keep[] = $winners[0];
                continue;
            }

            [$subsetKeep, $subsetDrop, $unresolved] = $this->resolveSampleSubsets($winners);
            array_push($keep, ...$subsetKeep);
            array_push($drop, ...$subsetDrop);
            if (count($unresolved) > 1) {
                $flagged[] = $unresolved;
            }
            array_push($keep, ...$unresolved);
        }

        return ['keep' => $keep, 'drop' => $drop, 'flagged' => $flagged];
    }

    /**
     * Sweep-line grouping by transitive time overlap. $records must already
     * be sorted by startTime.
     *
     * @param Record[] $records
     * @return list<Record[]>
     */
    private function groupOverlapping(array $records): array
    {
        $groups = [];
        $current = [];
        $currentEnd = null;

        foreach ($records as $record) {
            $end = $record->getEndTime() ?? $record->getStartTime();

            if ($current !== [] && $record->getStartTime() > $currentEnd) {
                $groups[] = $current;
                $current = [];
                $currentEnd = null;
            }

            $current[] = $record;
            $currentEnd = $currentEnd === null ? $end : max($currentEnd, $end);
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param Record[] $others
     */
    private function overlapsAny(Record $record, array $others): bool
    {
        $start = $record->getStartTime();
        $end = $record->getEndTime() ?? $start;

        foreach ($others as $other) {
            $otherStart = $other->getStartTime();
            $otherEnd = $other->getEndTime() ?? $otherStart;
            if ($start <= $otherEnd && $otherStart <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Among records tied at the same (top) priority, collapse any whose
     * `samples[]` is a literal subset of another's. Records that can't be
     * resolved this way (no samples, or genuinely differing samples) come
     * back as $unresolved rather than being guessed at.
     *
     * @param Record[] $records
     * @return array{0: Record[], 1: Record[], 2: Record[]} [keep, drop, unresolved]
     */
    private function resolveSampleSubsets(array $records): array
    {
        $dropped = [];

        foreach ($records as $a) {
            if (in_array($a, $dropped, true)) {
                continue;
            }
            foreach ($records as $b) {
                if ($a === $b || in_array($b, $dropped, true)) {
                    continue;
                }
                if ($this->isProperSampleSubset($a, $b)) {
                    $dropped[] = $a;
                    break;
                }
            }
        }

        $unresolved = array_values(array_filter($records, static fn (Record $r) => !in_array($r, $dropped, true)));

        return [[], $dropped, $unresolved];
    }

    /**
     * True if $a's samples are entirely contained in $b's (by exact value
     * match) and $a isn't the one that should win a tie on identical sets.
     */
    private function isProperSampleSubset(Record $a, Record $b): bool
    {
        $samplesA = $a->getPayload()['samples'] ?? null;
        $samplesB = $b->getPayload()['samples'] ?? null;
        if (!is_array($samplesA) || !is_array($samplesB) || $samplesA === [] || count($samplesA) > count($samplesB)) {
            return false;
        }

        $setB = array_map(static fn ($sample) => json_encode($sample), $samplesB);
        foreach ($samplesA as $sample) {
            if (!in_array(json_encode($sample), $setB, true)) {
                return false;
            }
        }

        if (count($samplesA) < count($samplesB)) {
            return true;
        }

        // Identical sets: keep the lower id, drop the other.
        return $a->getId() > $b->getId();
    }
}
