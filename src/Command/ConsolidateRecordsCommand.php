<?php

namespace App\Command;

use App\Entity\Record;
use App\Enum\RecordType;
use App\Repository\RecordRepository;
use App\Service\Consolidation\OverlapResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 1 of the "consolidation" plan (see LLM wiki concepts/consolidation.md):
 * a one-off backlog pass over the live `records` table, reviewed with
 * --dry-run before anything is actually deleted. Three passes, in order,
 * each one operating on what the previous pass left behind:
 *
 *   1. Hard-delete deleted=true tombstones.
 *   2. Exact-duplicate purge: same canonical type + identical start_time
 *      (e.g. /v1/ingest retries re-posting a LocationFix under a fresh
 *      recordUid) — keep the richer payload, tie-break lowest id.
 *   3. Overlap resolution via OverlapResolver: same canonical type,
 *      time-overlapping but not identical — resolved by dataOrigin
 *      priority, then by sample-array subset, never by summing/averaging.
 *      Anything neither rule can decide is left alone and reported.
 *
 * Phase 2 (a recurring `ConsolidationSchedule` job) is not built yet — see
 * the wiki doc's "Proposed shape" for that.
 */
#[AsCommand(
    name: 'app:consolidate:records',
    description: 'Hard-delete tombstones and collapse duplicate/overlapping records (see LLM wiki concepts/consolidation.md)',
)]
class ConsolidateRecordsCommand extends Command
{
    public function __construct(
        private readonly RecordRepository $records,
        private readonly OverlapResolver $overlaps,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen without deleting anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $io->title($dryRun ? 'Consolidate records (dry run)' : 'Consolidate records');

        $tombstones = $this->consolidateTombstones($dryRun);
        $io->writeln(sprintf('Tombstones: %d %s', $tombstones, $dryRun ? 'would be hard-deleted' : 'hard-deleted'));

        [$dupeIds, $canonicalTypes] = $this->consolidateExactDuplicates($dryRun);
        $io->writeln(sprintf('Exact duplicates: %d %s', count($dupeIds), $dryRun ? 'would be hard-deleted' : 'hard-deleted'));

        [$overlapDrops, $flaggedGroups] = $this->consolidateOverlaps($dryRun, $canonicalTypes, $dupeIds);
        $io->writeln(sprintf('Overlap losers: %d %s', $overlapDrops, $dryRun ? 'would be hard-deleted' : 'hard-deleted'));

        if ($flaggedGroups !== []) {
            $io->section(sprintf('%d overlapping group(s) left unresolved (equal priority, not a subset of each other) — needs manual review', count($flaggedGroups)));
            foreach ($flaggedGroups as $group) {
                $io->writeln(sprintf(
                    '  type=%s ids=[%s] dataOrigins=[%s] window=%s..%s',
                    $group[0]->getType(),
                    implode(',', array_map(static fn (Record $r) => $r->getId(), $group)),
                    implode(',', array_unique(array_map(static fn (Record $r) => $r->getPayload()['dataOrigin'] ?? 'null', $group))),
                    $group[0]->getStartTime()->format(\DateTimeInterface::ATOM),
                    max(array_map(static fn (Record $r) => $r->getEndTime() ?? $r->getStartTime(), $group))->format(\DateTimeInterface::ATOM),
                ));
            }
        }

        if ($dryRun) {
            $io->note('Dry run: nothing was deleted. Re-run without --dry-run to apply.');
        } else {
            $io->success('Consolidation complete.');
        }

        return Command::SUCCESS;
    }

    private function consolidateTombstones(bool $dryRun): int
    {
        if ($dryRun) {
            return $this->records->countDeleted();
        }

        return $this->records->hardDeleteTombstones();
    }

    /**
     * @return array{0: int[], 1: string[]} [ids removed (or that would be), canonical types touched]
     */
    private function consolidateExactDuplicates(bool $dryRun): array
    {
        $canonicalTypes = $this->canonicalTypesPresent();
        $toDelete = [];

        foreach ($canonicalTypes as $canonicalType) {
            $records = $this->records->findLiveByTypeIn(RecordType::variants($canonicalType));

            $byStartTime = [];
            foreach ($records as $record) {
                $byStartTime[$record->getStartTime()->format(\DateTimeInterface::ATOM)][] = $record;
            }

            foreach ($byStartTime as $group) {
                if (count($group) < 2) {
                    continue;
                }
                usort($group, static fn (Record $a, Record $b) => self::richness($b) <=> self::richness($a) ?: $a->getId() <=> $b->getId());
                array_shift($group); // keep the richest / lowest-id row
                foreach ($group as $loser) {
                    $toDelete[] = $loser->getId();
                }
            }
        }

        if (!$dryRun && $toDelete !== []) {
            $this->records->hardDeleteByIds($toDelete);
        }

        return [$toDelete, $canonicalTypes];
    }

    /**
     * $excludeIds are rows the exact-duplicate pass already decided to
     * remove — excluded here too (even in dry-run, where they're still
     * physically in the DB) so the overlap pass reports what would happen
     * to what's left *after* deduping, matching a real (non-dry) run.
     *
     * @param string[] $canonicalTypes
     * @param int[] $excludeIds
     * @return array{0: int, 1: Record[][]} [deleted count, flagged groups]
     */
    private function consolidateOverlaps(bool $dryRun, array $canonicalTypes, array $excludeIds): array
    {
        $toDelete = [];
        $flagged = [];

        foreach ($canonicalTypes as $canonicalType) {
            $records = $this->records->findLiveByTypeIn(RecordType::variants($canonicalType));
            $records = array_values(array_filter($records, static fn (Record $r) => !in_array($r->getId(), $excludeIds, true)));
            $resolution = $this->overlaps->resolve($records);

            foreach ($resolution['drop'] as $loser) {
                $toDelete[] = $loser->getId();
            }
            array_push($flagged, ...$resolution['flagged']);
        }

        if (!$dryRun && $toDelete !== []) {
            $this->records->hardDeleteByIds($toDelete);
        }

        return [count($toDelete), $flagged];
    }

    /**
     * @return string[]
     */
    private function canonicalTypesPresent(): array
    {
        $types = array_map(static fn (array $row) => (string) $row['value'], $this->records->countGroupedBy('type'));
        $canonical = array_map(RecordType::canonicalize(...), $types);

        return array_values(array_unique($canonical));
    }

    private static function richness(Record $record): int
    {
        return count(array_filter($record->getPayload(), static fn ($v) => $v !== null && $v !== '' && $v !== []));
    }
}
