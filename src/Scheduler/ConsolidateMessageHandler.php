<?php

namespace App\Scheduler;

use App\Entity\Record;
use App\Enum\RecordType;
use App\Repository\RecordRepository;
use App\Scheduler\Message\ConsolidateMessage;
use App\Service\Consolidation\ConsolidationEngine;
use App\Service\Consolidation\DailyStepsMerger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs every 30 min (see ConsolidationSchedule). Unlike the Phase-1 backlog
 * command (app:consolidate:records), which rescans a whole canonical
 * type's entire history, this only looks at rows synced since the last
 * tick and checks each against a bounded time-window of existing
 * neighbors of the same canonical type — cheap enough to run forever, per
 * the wiki doc's "Proposed shape" (re-scanning everything every tick was
 * measured taking over two minutes for the full table).
 *
 * Starts in log-only mode (CONSOLIDATION_LIVE_DELETES=false, see .env) per
 * the two-phase rollout Stan asked for: several ticks of "here's what I
 * would delete" in var/log/consolidation.log before flipping to real
 * deletes.
 */
#[AsMessageHandler]
class ConsolidateMessageHandler
{
    private const STEPS_CANONICAL_TYPE = 'Steps';

    // Generous enough to catch a same-day ingest retry or a wide daily
    // rollup overlapping narrower same-day bursts, without re-scanning
    // unrelated history for every tick.
    private const NEIGHBOR_PADDING = '1 day';

    public function __construct(
        private readonly RecordRepository $records,
        private readonly ConsolidationEngine $engine,
        private readonly DailyStepsMerger $stepsMerger,
        private readonly bool $liveDeletes,
        private readonly string $stateFile,
        private readonly string $logFile,
    ) {
    }

    public function __invoke(ConsolidateMessage $message): void
    {
        $tickAt = new \DateTimeImmutable();
        $cursor = $this->readCursor();

        $tombstones = $this->liveDeletes ? $this->records->hardDeleteTombstones() : $this->records->countDeleted();

        $newRecords = $this->records->findByReceivedAtAfter($cursor);
        if ($newRecords === []) {
            $this->log($tickAt, sprintf('no new records since %s; tombstones=%d', $cursor->format(\DateTimeInterface::ATOM), $tombstones));

            return;
        }

        $byCanonicalType = [];
        foreach ($newRecords as $record) {
            $byCanonicalType[RecordType::canonicalize($record->getType())][] = $record;
        }

        $deleted = 0;
        $flaggedCount = 0;
        foreach ($byCanonicalType as $canonicalType => $fresh) {
            $candidates = $this->mergeById($this->findNeighbors($canonicalType, $fresh), $fresh);

            if ($canonicalType === self::STEPS_CANONICAL_TYPE) {
                foreach ($this->stepsMerger->groupByCalendarDay($candidates) as $dayRecords) {
                    $deleted += count($this->stepsMerger->mergeDay($dayRecords, !$this->liveDeletes));
                }
                continue;
            }

            $result = $this->engine->consolidateBucket($candidates, !$this->liveDeletes);
            $deleted += count($result['exactDuplicateIds']) + count($result['overlapIds']);
            $flaggedCount += count($result['flagged']);
        }

        $nextCursor = max(array_map(static fn (Record $r) => $r->getReceivedAt(), $newRecords));
        $this->writeCursor($nextCursor);

        $this->log($tickAt, sprintf(
            'new=%d tombstones=%d %s=%d flagged=%d mode=%s',
            count($newRecords),
            $tombstones,
            $this->liveDeletes ? 'deleted' : 'would_delete',
            $deleted,
            $flaggedCount,
            $this->liveDeletes ? 'live' : 'log-only',
        ));
    }

    /**
     * @param Record[] $fresh
     * @return Record[]
     */
    private function findNeighbors(string $canonicalType, array $fresh): array
    {
        $starts = array_map(static fn (Record $r) => $r->getStartTime(), $fresh);
        $ends = array_map(static fn (Record $r) => $r->getEndTime() ?? $r->getStartTime(), $fresh);

        $from = min($starts)->modify('-'.self::NEIGHBOR_PADDING);
        $to = max($ends)->modify('+'.self::NEIGHBOR_PADDING);

        return $this->records->findLiveByTypeInRange(RecordType::variants($canonicalType), $from, $to);
    }

    /**
     * @param Record[] $a
     * @param Record[] $b
     * @return Record[]
     */
    private function mergeById(array $a, array $b): array
    {
        $byId = [];
        foreach ([...$a, ...$b] as $record) {
            $byId[$record->getId()] = $record;
        }

        return array_values($byId);
    }

    /**
     * No state file yet means this is the very first tick — start from the
     * latest row already in the DB rather than the epoch, so the first run
     * doesn't try to re-walk everything Phase 1's backlog command already
     * covers. Anything synced in the gap between that backlog pass and this
     * job's first tick is still picked up, since latestReceivedAt() reflects
     * the DB as it is right now.
     */
    private function readCursor(): \DateTimeImmutable
    {
        if (is_file($this->stateFile)) {
            $raw = json_decode(file_get_contents($this->stateFile), true);
            if (isset($raw['cursor'])) {
                return new \DateTimeImmutable($raw['cursor']);
            }
        }

        return $this->records->latestReceivedAt() ?? new \DateTimeImmutable();
    }

    private function writeCursor(\DateTimeImmutable $cursor): void
    {
        file_put_contents($this->stateFile, json_encode(['cursor' => $cursor->format(\DateTimeInterface::ATOM)], \JSON_PRETTY_PRINT));
    }

    private function log(\DateTimeImmutable $at, string $message): void
    {
        file_put_contents($this->logFile, sprintf("[%s] %s\n", $at->format('c'), $message), \FILE_APPEND);
    }
}
