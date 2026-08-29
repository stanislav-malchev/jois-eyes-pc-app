<?php

namespace App\Command;

use App\Entity\Record;
use App\Enum\RecordType;
use App\Repository\RecordRepository;
use App\Service\Consolidation\ConsolidationEngine;
use App\Service\Consolidation\DailyStepsMerger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 1 of the "consolidation" plan (see LLM wiki concepts/consolidation.md):
 * a one-off backlog pass over the live `records` table, reviewed with
 * --dry-run before anything is actually deleted.
 *
 *   1. Hard-delete deleted=true tombstones.
 *   2. Steps: DailyStepsMerger collapses each calendar day to one row
 *      (Health Connect's own total when present, otherwise whatever's
 *      there), per Stan's 29.08.2026 correction — see that class.
 *   3. Every other type: ConsolidationEngine (exact-duplicate purge, then
 *      dataOrigin-priority/subset overlap resolution — never summing across
 *      apps). Anything neither rule can decide is left alone and reported.
 *
 * Phase 2 (the recurring ConsolidationSchedule job, App\Scheduler\
 * ConsolidateMessageHandler) reuses the same services against a much
 * smaller, time-windowed candidate set instead of rescanning everything on
 * every tick — see that class for why a full rescan isn't repeated there.
 */
#[AsCommand(
    name: 'app:consolidate:records',
    description: 'Hard-delete tombstones and collapse duplicate/overlapping records (see LLM wiki concepts/consolidation.md)',
)]
class ConsolidateRecordsCommand extends Command
{
    private const STEPS_CANONICAL_TYPE = 'Steps';

    public function __construct(
        private readonly RecordRepository $records,
        private readonly ConsolidationEngine $engine,
        private readonly DailyStepsMerger $stepsMerger,
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

        $tombstones = $dryRun ? $this->records->countDeleted() : $this->records->hardDeleteTombstones();
        $io->writeln(sprintf('Tombstones: %d %s', $tombstones, $dryRun ? 'would be hard-deleted' : 'hard-deleted'));

        $stepsRemoved = $this->consolidateSteps($dryRun);
        $io->writeln(sprintf('Steps daily merge: %d row(s) %s', $stepsRemoved, $dryRun ? 'would be removed' : 'removed'));

        $exactDupeCount = 0;
        $overlapCount = 0;
        $flaggedGroups = [];

        foreach ($this->canonicalTypesPresent() as $canonicalType) {
            if ($canonicalType === self::STEPS_CANONICAL_TYPE) {
                continue;
            }

            $records = $this->records->findLiveByTypeIn(RecordType::variants($canonicalType));
            $result = $this->engine->consolidateBucket($records, $dryRun);

            $exactDupeCount += count($result['exactDuplicateIds']);
            $overlapCount += count($result['overlapIds']);
            array_push($flaggedGroups, ...$result['flagged']);
        }

        $io->writeln(sprintf('Exact duplicates: %d %s', $exactDupeCount, $dryRun ? 'would be hard-deleted' : 'hard-deleted'));
        $io->writeln(sprintf('Overlap losers: %d %s', $overlapCount, $dryRun ? 'would be hard-deleted' : 'hard-deleted'));

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

    private function consolidateSteps(bool $dryRun): int
    {
        $records = $this->records->findLiveByTypeIn(RecordType::variants(self::STEPS_CANONICAL_TYPE));
        $removed = 0;
        foreach ($this->stepsMerger->groupByCalendarDay($records) as $dayRecords) {
            $removed += count($this->stepsMerger->mergeDay($dayRecords, $dryRun));
        }

        return $removed;
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
}
