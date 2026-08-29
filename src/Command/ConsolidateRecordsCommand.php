<?php

namespace App\Command;

use App\Entity\Record;
use App\Enum\RecordType;
use App\Repository\RecordRepository;
use App\Service\Consolidation\ConsolidationEngine;
use App\Service\Consolidation\DailyStepsConsolidator;
use App\Service\Normalization\RecordMetricsExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 1 of the "consolidation" plan (see LLM wiki concepts/consolidation.md):
 * a one-off backlog pass over the live `records` table, reviewed with
 * --dry-run before anything is marked deleted.
 *
 *   1. Hard-delete deleted=true tombstones — safe to physically remove,
 *      see RecordRepository::hardDeleteTombstones().
 *   2. Steps: DailyStepsConsolidator soft-deletes each calendar day's
 *      losing-priority rows, leaving the winning tier's rows untouched
 *      (never merged/mutated) — see that class for why.
 *   3. Every other type: ConsolidationEngine (exact-duplicate purge, then
 *      dataOrigin-priority/subset overlap resolution — never summing across
 *      apps). Anything neither rule can decide is left alone and reported.
 *   4. RecordMetricsExtractor backfills the denormalized metric columns
 *      (dataOrigin/metricValue/min/max/sampleCount) on every live row this
 *      command already loads for steps 2-3 — a side effect piggybacked on
 *      the existing full scan rather than a separate pass. Pure/idempotent
 *      recompute from payload_json, unrelated to the delete decisions
 *      above; skipped entirely on --dry-run so a dry run changes nothing.
 *
 * Steps 2 and 3 only ever soft-delete (RecordRepository::markDeleted())
 * — never a hard DELETE and never a payload mutation — so a future resync
 * of anything consolidation touched just upserts back to the same state,
 * no duplication or data loss either way. See markDeleted()'s
 * docblock for the incident that made this non-negotiable.
 *
 * Phase 2 (the recurring ConsolidationSchedule job, App\Scheduler\
 * ConsolidateMessageHandler) reuses the same services against a much
 * smaller, time-windowed candidate set instead of rescanning everything on
 * every tick — see that class for why a full rescan isn't repeated there.
 */
#[AsCommand(
    name: 'app:consolidate:records',
    description: 'Hard-delete tombstones and soft-delete duplicate/overlapping records (see LLM wiki concepts/consolidation.md)',
)]
class ConsolidateRecordsCommand extends Command
{
    private const STEPS_CANONICAL_TYPE = 'Steps';

    public function __construct(
        private readonly RecordRepository $records,
        private readonly ConsolidationEngine $engine,
        private readonly DailyStepsConsolidator $stepsConsolidator,
        private readonly RecordMetricsExtractor $metrics,
        private readonly EntityManagerInterface $em,
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

        $stepsMarked = $this->consolidateSteps($dryRun);
        $io->writeln(sprintf('Steps: %d row(s) %s', $stepsMarked, $dryRun ? 'would be marked deleted' : 'marked deleted'));

        $exactDupeCount = 0;
        $overlapCount = 0;
        $flaggedGroups = [];

        foreach ($this->canonicalTypesPresent() as $canonicalType) {
            if ($canonicalType === self::STEPS_CANONICAL_TYPE) {
                continue;
            }

            $records = $this->records->findLiveByTypeIn(RecordType::variants($canonicalType));
            if (!$dryRun) {
                array_map($this->metrics->extract(...), $records);
            }
            $result = $this->engine->consolidateBucket($records, $dryRun);

            $exactDupeCount += count($result['exactDuplicateIds']);
            $overlapCount += count($result['overlapIds']);
            array_push($flaggedGroups, ...$result['flagged']);
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf('Exact duplicates: %d %s', $exactDupeCount, $dryRun ? 'would be marked deleted' : 'marked deleted'));
        $io->writeln(sprintf('Overlap losers: %d %s', $overlapCount, $dryRun ? 'would be marked deleted' : 'marked deleted'));

        if ($flaggedGroups !== []) {
            $io->section(sprintf('%d overlapping group(s) left unresolved (equal priority, not a subset of each other) — needs manual review', count($flaggedGroups)));
            foreach ($flaggedGroups as $group) {
                $io->writeln(sprintf(
                    '  type=%s ids=[%s] dataOrigins=[%s] window=%s..%s',
                    $group[0]->getType(),
                    implode(',', array_map(static fn (Record $r) => $r->getId(), $group)),
                    implode(',', array_unique(array_map(static fn (Record $r) => $r->getDataOrigin() ?? 'null', $group))),
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
        if (!$dryRun) {
            array_map($this->metrics->extract(...), $records);
        }

        $marked = 0;
        foreach ($this->stepsConsolidator->groupByCalendarDay($records) as $dayRecords) {
            $marked += count($this->stepsConsolidator->consolidateDay($dayRecords, $dryRun));
        }

        return $marked;
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
