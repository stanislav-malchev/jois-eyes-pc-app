<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Merges records_import (see ImportXiaomiCsvCommand) into records.
 *
 * Every records_import row is copied into records verbatim — record_uid,
 * source, type, start_time, end_time, ingested_at, received_at, payload —
 * except `deleted`, which gets recomputed here:
 *
 *   - already deleted=1 in records_import (superseded by a higher-priority
 *     Xiaomi source at import time — see dedupeWithinImport()) stays
 *     deleted; no need to check records at all.
 *   - otherwise deleted=1 if records already has a *different* live
 *     (deleted=0) row for the same (type, start_time) — either a genuine
 *     Health Connect sync, or a row from an earlier merge run — so the
 *     same real-world window never gets double-counted (double steps,
 *     double sleep, etc.) once both tables' data lives together here.
 *   - otherwise deleted=0: genuinely new historical coverage records
 *     didn't have.
 *
 * The "existing coverage" snapshot is read once from records before any
 * writes happen, so within a single run one records_import row's outcome
 * never depends on another's — records_import already guarantees at most
 * one live row per (type, start_time) (dedupeWithinImport()), so nothing
 * inserted this run can collide with anything else inserted this run.
 *
 * Reuses records_import's recordUid scheme ("xiaomi:{sid}:{key}:..."), so
 * re-running this command (e.g. after re-running the import) is a safe
 * upsert, not a duplicate insert — a record_uid already in records from an
 * earlier merge is excluded from counting as "a different row" against
 * itself, so re-merging unchanged data doesn't flip anything to deleted.
 */
#[AsCommand(
    name: 'app:merge:records-import',
    description: 'Merge records_import into records, deduping against existing live records by (type, start_time)',
)]
class MergeRecordsImportCommand extends Command
{
    private const BATCH_SIZE = 2000;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen without writing to records');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->writeln('Loading existing live (type, start_time) coverage from records...');
        $existingByKey = [];
        $existingRows = $this->connection->executeQuery('SELECT record_uid, type, start_time FROM records WHERE deleted = 0');
        while ($row = $existingRows->fetchAssociative()) {
            $existingByKey[$row['type']."\0".$row['start_time']][$row['record_uid']] = true;
        }

        $sql = 'INSERT OR REPLACE INTO records
            (record_uid, source, type, start_time, end_time, ingested_at, received_at, deleted, payload)
            VALUES (:record_uid, :source, :type, :start_time, :end_time, :ingested_at, :received_at, :deleted, :payload)';
        $stmt = $this->connection->prepare($sql);

        $total = 0;
        $keptLive = 0;
        $newlyDeduped = 0;
        $alreadyDeleted = 0;
        $inBatch = 0;

        if (!$dryRun) {
            $this->connection->beginTransaction();
        }

        $rows = $this->connection->executeQuery(
            'SELECT record_uid, source, type, start_time, end_time, ingested_at, received_at, deleted, payload FROM records_import',
        );
        while ($row = $rows->fetchAssociative()) {
            ++$total;

            if (1 === (int) $row['deleted']) {
                $deleted = 1;
                ++$alreadyDeleted;
            } else {
                $key = $row['type']."\0".$row['start_time'];
                $bucket = $existingByKey[$key] ?? [];
                $othersCount = \count($bucket) - (isset($bucket[$row['record_uid']]) ? 1 : 0);
                if ($othersCount > 0) {
                    $deleted = 1;
                    ++$newlyDeduped;
                } else {
                    $deleted = 0;
                    ++$keptLive;
                }
            }

            if (!$dryRun) {
                $stmt->bindValue('record_uid', $row['record_uid']);
                $stmt->bindValue('source', $row['source']);
                $stmt->bindValue('type', $row['type']);
                $stmt->bindValue('start_time', $row['start_time']);
                null === $row['end_time']
                    ? $stmt->bindValue('end_time', null, ParameterType::NULL)
                    : $stmt->bindValue('end_time', $row['end_time']);
                $stmt->bindValue('ingested_at', $row['ingested_at']);
                $stmt->bindValue('received_at', $row['received_at']);
                $stmt->bindValue('deleted', $deleted, ParameterType::INTEGER);
                $stmt->bindValue('payload', $row['payload']);
                $stmt->executeStatement();

                if (++$inBatch >= self::BATCH_SIZE) {
                    $this->connection->commit();
                    $this->connection->beginTransaction();
                    $inBatch = 0;
                }
            }
        }

        if (!$dryRun) {
            $this->connection->commit();
        }

        $io->success(sprintf(
            '%d rows processed%s: %d kept live, %d newly deduped against existing records, %d already deleted from import-time dedup',
            $total,
            $dryRun ? ' [DRY RUN, nothing written]' : '',
            $keptLive,
            $newlyDeduped,
            $alreadyDeleted,
        ));

        return Command::SUCCESS;
    }
}
