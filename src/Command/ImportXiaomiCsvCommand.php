<?php

namespace App\Command;

use App\Enum\RecordSource;
use App\Enum\RecordType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Statement;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports a Xiaomi Mi Fitness "fitness_data" CSV export into records_import
 * (a staging copy of the records schema — see migration
 * Version20260822074248) for review before merging into records.
 *
 * CSV columns: Uid,Sid,Key,Time,Value,UpdateTime — Uid and UpdateTime are
 * not stored. Mapping: Sid -> source, Key -> type, Time (unix seconds) ->
 * start_time, Value (already JSON) -> payload. end_time is left null for
 * most types; the CSV carries no such field and most Value payloads don't
 * embed a session end either. `sleep` is the exception — see "Payload
 * shape" below.
 *
 * recordUid is synthesized as "xiaomi:{sid}:{key}:{time}" since the export
 * has no natural id column; verified against this export to be unique per
 * row, and it's stable across re-runs so importing twice is a no-op. Note
 * recordUid always uses the raw CSV Key, even for the three types that get
 * normalized (see TYPE_MAP below) — it's a stable identity independent of
 * how we currently choose to label the type.
 *
 * Key -> type normalizes three concepts that already exist under different
 * names in records (Health-Connect-derived, from the phone): Xiaomi's
 * steps/heart_rate/sleep become StepsRecord/HeartRateRecord/SleepSessionRecord
 * so both sources use one type string per concept. Everything else in
 * RecordType (spo2, watch_night_sleep, etc.) is Xiaomi-only and passes
 * through verbatim — watch_night_sleep in particular is deliberately kept
 * distinct from SleepSessionRecord (different device/structure).
 *
 * SKIP_TYPES (raw CSV Key, checked before TYPE_MAP) are read but never
 * written — `calories` is high-volume and not something we need staged
 * while working out the steps/heart-rate timestamp matching. SKIP_SOURCES
 * drops xiaomiwear_app_manually entirely (7 rows total) — manually-entered
 * readings, not worth reconciling.
 *
 * Timestamps: verified against records over the one overlapping window
 * (source 790547985, 2026-08-18 through 2026-08-22) that Xiaomi's Time
 * column is already true UTC epoch seconds — 32/32 StepsRecord 30-minute
 * bucket sums and 119/119 HeartRateRecord (time, bpm) samples matched
 * exactly at zero offset. No timezone correction is applied here.
 *
 * Payload shape: like the type string, a row's payload needs to match
 * what records already stores for that type before a merge makes sense —
 * so far only `sleep` gets this treatment (see transformSleepPayload()),
 * converting Xiaomi's {bedtime,wake_up_time,items:[{start_time,end_time,
 * state}]} into Health Connect's {startTime,endTime,title,stages:[
 * {startTime,endTime,stage}],dataOrigin}. `state` -> stage name (2=deep,
 * 3=light, 4=rem, 5=awake) matches the independently-reverse-engineered
 * mapping in github.com/hayeon17kim/mi-fitness-mac-export's export.py, and
 * was cross-checked here against sleep_deep/light_duration sums across the
 * dataset. Also fixes start_time/end_time: the CSV's Time column for
 * `sleep` rows is protoTime (== wake_up_time, i.e. when the record was
 * generated), not the session's actual start — verified equal to
 * wake_up_time for all 196 `sleep` rows that carry items. Rows with no
 * `items` (125 of 321) are short unstaged naps; they still get a session
 * with real start/end and an empty stages array rather than being rejected
 * outright. avg_hr/max_hr/min_hr aren't part of the Health Connect shape
 * and are dropped — that data already exists via heart_rate-type rows.
 *
 * `spo2` (see transformSpo2Payload()) is converted blind — the phone app
 * doesn't sync Health Connect's OxygenSaturationRecord yet, so there's no
 * real payload to check the shape against. {time, spo2} becomes {time,
 * percentage, dataOrigin}; revisit once the app actually syncs this type
 * and we can see the real wire format.
 *
 * steps/heart_rate are a different shape of problem: records stores these
 * as 30-minute buckets (verified: all 95 existing StepsRecord/
 * HeartRateRecord rows have start aligned to :00/:30 — epoch % 1800 === 0
 * — and end === start + 1799), one bucket per (source, window) with real
 * activity, while Xiaomi's CSV has one row per minute (steps) or per
 * reading (heart_rate). So these two types can't be transformed row by
 * row like sleep — they're aggregated in memory into BUCKET_SECONDS
 * windows per source (stepsBuckets/heartRateBuckets) while the CSV
 * streams past, then flushed as synthetic rows once the whole file has
 * been read. recordUid for these becomes "xiaomi:{sid}:{key}:{bucketStart}"
 * — same format as every other type, just keyed by bucket start instead
 * of a raw CSV row's time, which is still deterministic/idempotent across
 * re-imports. A steps bucket summing to 0 is dropped rather than written
 * (verified: no zero-count StepsRecord exists in records — Health Connect
 * only ever creates a bucket when there's real activity in it).
 *
 * Final phase, dedupeWithinImport(): the three Xiaomi sources overlap in
 * history and sometimes both cover the same (type, start_time) window with
 * genuinely different values — marks every row but the best one (by
 * SOURCE_PRIORITY) deleted so that doesn't get double-counted once this
 * lands in records. This is dedup *within* records_import; deduping
 * against records' own pre-existing rows is a separate, later step.
 */
#[AsCommand(
    name: 'app:import:xiaomi-csv',
    description: 'Import a Xiaomi Mi Fitness CSV export into the records_import staging table',
)]
class ImportXiaomiCsvCommand extends Command
{
    private const BATCH_SIZE = 2000;
    private const EXPECTED_HEADER = ['Uid', 'Sid', 'Key', 'Time', 'Value', 'UpdateTime'];
    private const TYPE_MAP = [
        'steps' => RecordType::STEPS->value,
        'heart_rate' => RecordType::HEART_RATE->value,
        'sleep' => RecordType::SLEEP->value,
        'spo2' => RecordType::SPO2->value,
    ];
    private const SKIP_TYPES = [RecordType::CALORIES->value];
    private const SKIP_SOURCES = [RecordSource::XIAOMI_WEAR_APP_MANUALLY->value];
    private const SLEEP_STATE_MAP = [2 => 'deep', 3 => 'light', 4 => 'rem', 5 => 'awake'];
    private const BUCKET_SECONDS = 1800;
    /**
     * Lower rank wins when multiple Xiaomi sources produce a row for the
     * same (type, start_time) — see the dedupeWithinImport() doc.
     */
    private const SOURCE_PRIORITY = [
        RecordSource::MI_FITNESS_APP->value => 1,
        RecordSource::XIAOMI_HLTH_GEN->value => 2,
        RecordSource::XIAOMI_SPORTS_APP->value => 3,
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('csv-path', InputArgument::REQUIRED, 'Path to the Xiaomi CSV export')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after importing this many data rows (for a quick trial run)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and report only, do not write to records_import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('csv-path');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($path) || !is_readable($path)) {
            $io->error(sprintf('CSV file not found or not readable: %s', $path));

            return Command::FAILURE;
        }

        $handle = fopen($path, 'r');
        if (false === $handle) {
            $io->error(sprintf('Could not open: %s', $path));

            return Command::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header !== self::EXPECTED_HEADER) {
            $io->error(sprintf(
                'Unexpected CSV header. Expected %s, got %s',
                implode(',', self::EXPECTED_HEADER),
                implode(',', $header ?: []),
            ));
            fclose($handle);

            return Command::FAILURE;
        }

        $runAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = 'INSERT OR REPLACE INTO records_import
            (record_uid, source, type, start_time, end_time, ingested_at, received_at, deleted, payload)
            VALUES (:record_uid, :source, :type, :start_time, :end_time, :ingested_at, :received_at, 0, :payload)';
        $stmt = $this->connection->prepare($sql);

        $io->writeln(sprintf('Importing into records_import (ingested_at/received_at = %s)%s', $runAt, $dryRun ? ' [DRY RUN]' : ''));

        $read = 0;
        $imported = 0;
        $rejected = 0;
        $skipped = 0;
        $bucketedRows = 0;
        $inBatch = 0;
        $rejectLog = \dirname(__DIR__, 2).'/var/log/xiaomi_import_rejected.log';
        $stepsBuckets = [];
        $heartRateBuckets = [];

        if (!$dryRun) {
            $this->connection->beginTransaction();
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (null !== $limit && $read >= $limit) {
                break;
            }
            ++$read;

            [$uid, $sid, $key, $time, $value, $updateTime] = $row + [null, null, null, null, null, null];
            $payload = json_decode((string) $value, true);

            if (null === $sid || null === $key || null === $time || !\is_array($payload)) {
                ++$rejected;
                file_put_contents(
                    $rejectLog,
                    sprintf("[%s] row %d: %s\n", $runAt, $read, json_encode($row)),
                    FILE_APPEND,
                );
                continue;
            }

            if (\in_array($key, self::SKIP_TYPES, true) || \in_array($sid, self::SKIP_SOURCES, true)) {
                ++$skipped;
                continue;
            }

            $startTimeEpoch = (int) $time;
            $endTimeEpoch = null;

            if ('steps' === $key) {
                if (!isset($payload['steps'])) {
                    ++$rejected;
                    file_put_contents(
                        $rejectLog,
                        sprintf("[%s] row %d: steps payload missing 'steps': %s\n", $runAt, $read, json_encode($row)),
                        FILE_APPEND,
                    );
                    continue;
                }
                $bucketStart = $startTimeEpoch - ($startTimeEpoch % self::BUCKET_SECONDS);
                $stepsBuckets[$sid][$bucketStart] = ($stepsBuckets[$sid][$bucketStart] ?? 0) + (int) $payload['steps'];
                ++$bucketedRows;
                continue;
            }

            if ('heart_rate' === $key) {
                if (!isset($payload['bpm'])) {
                    ++$rejected;
                    file_put_contents(
                        $rejectLog,
                        sprintf("[%s] row %d: heart_rate payload missing 'bpm': %s\n", $runAt, $read, json_encode($row)),
                        FILE_APPEND,
                    );
                    continue;
                }
                $bucketStart = $startTimeEpoch - ($startTimeEpoch % self::BUCKET_SECONDS);
                $heartRateBuckets[$sid][$bucketStart][] = ['time' => $startTimeEpoch, 'bpm' => (int) $payload['bpm']];
                ++$bucketedRows;
                continue;
            }

            if ('spo2' === $key) {
                $transformed = $this->transformSpo2Payload($payload);
                if (null === $transformed) {
                    ++$rejected;
                    file_put_contents(
                        $rejectLog,
                        sprintf("[%s] row %d: unrecognized spo2 payload shape: %s\n", $runAt, $read, json_encode($row)),
                        FILE_APPEND,
                    );
                    continue;
                }
                $payload = $transformed;
            }

            if ('sleep' === $key) {
                $transformed = $this->transformSleepPayload($payload);
                if (null === $transformed) {
                    ++$rejected;
                    file_put_contents(
                        $rejectLog,
                        sprintf("[%s] row %d: unrecognized sleep payload shape: %s\n", $runAt, $read, json_encode($row)),
                        FILE_APPEND,
                    );
                    continue;
                }
                $startTimeEpoch = $payload['bedtime'];
                $endTimeEpoch = $payload['wake_up_time'];
                $payload = $transformed;
            }

            $startTime = gmdate('Y-m-d H:i:s', $startTimeEpoch);
            $endTime = null !== $endTimeEpoch ? gmdate('Y-m-d H:i:s', $endTimeEpoch) : null;
            $recordUid = sprintf('xiaomi:%s:%s:%s', $sid, $key, $time);
            $type = self::TYPE_MAP[$key] ?? $key;

            if (!$dryRun) {
                $this->bindAndInsert($stmt, $recordUid, $sid, $type, $startTime, $endTime, $runAt, $payload);
            }
            ++$imported;
            ++$inBatch;

            if (!$dryRun && $inBatch >= self::BATCH_SIZE) {
                $this->connection->commit();
                $this->connection->beginTransaction();
                $inBatch = 0;
            }

            if (0 === $read % 100000) {
                $io->writeln(sprintf('  ... %d rows read (%d imported, %d bucketed, %d skipped, %d rejected)', $read, $imported, $bucketedRows, $skipped, $rejected));
            }
        }

        if (!$dryRun) {
            $this->connection->commit();
        }

        fclose($handle);

        $io->writeln(sprintf('Flushing steps/heart-rate buckets from %d aggregated rows...', $bucketedRows));

        [$stepsEmitted, $stepsEmptySkipped] = $this->flushStepsBuckets($stmt, $stepsBuckets, $runAt, $dryRun);
        $heartRateEmitted = $this->flushHeartRateBuckets($stmt, $heartRateBuckets, $runAt, $dryRun);
        $imported += $stepsEmitted + $heartRateEmitted;
        $skipped += $stepsEmptySkipped;

        $duplicatesDeleted = 0;
        if (!$dryRun) {
            $io->writeln('Deduping within the import (same type + start_time, multiple Xiaomi sources)...');
            $duplicatesDeleted = $this->dedupeWithinImport();
        }

        $io->success(sprintf(
            '%d rows read, %d imported (%d steps buckets, %d heart-rate buckets), %d skipped (incl. %d empty steps buckets), %d rejected%s, %d duplicates marked deleted',
            $read,
            $imported,
            $stepsEmitted,
            $heartRateEmitted,
            $skipped,
            $stepsEmptySkipped,
            $rejected,
            $rejected > 0 ? sprintf(' (see %s)', $rejectLog) : '',
            $duplicatesDeleted,
        ));

        return Command::SUCCESS;
    }

    private function bindAndInsert(
        Statement $stmt,
        string $recordUid,
        string $source,
        string $type,
        string $startTime,
        ?string $endTime,
        string $runAt,
        array $payload,
    ): void {
        $stmt->bindValue('record_uid', $recordUid);
        $stmt->bindValue('source', $source);
        $stmt->bindValue('type', $type);
        $stmt->bindValue('start_time', $startTime);
        null === $endTime
            ? $stmt->bindValue('end_time', null, ParameterType::NULL)
            : $stmt->bindValue('end_time', $endTime);
        $stmt->bindValue('ingested_at', $runAt);
        $stmt->bindValue('received_at', $runAt);
        $stmt->bindValue('payload', json_encode($payload));
        $stmt->executeStatement();
    }

    /**
     * @param array<string, array<int, int>> $stepsBuckets sid => [bucketStartEpoch => summedSteps]
     *
     * @return array{0: int, 1: int} [emitted, emptySkipped]
     */
    private function flushStepsBuckets(Statement $stmt, array $stepsBuckets, string $runAt, bool $dryRun): array
    {
        $emitted = 0;
        $emptySkipped = 0;
        $inBatch = 0;

        if (!$dryRun) {
            $this->connection->beginTransaction();
        }

        foreach ($stepsBuckets as $sid => $buckets) {
            foreach ($buckets as $bucketStart => $sum) {
                if ($sum <= 0) {
                    ++$emptySkipped;
                    continue;
                }

                $payload = [
                    'startTime' => gmdate('Y-m-d\TH:i:s\Z', $bucketStart),
                    'endTime' => gmdate('Y-m-d\TH:i:s\Z', $bucketStart + self::BUCKET_SECONDS - 1),
                    'count' => $sum,
                    'dataOrigin' => 'com.xiaomi.wearable',
                ];

                if (!$dryRun) {
                    $this->bindAndInsert(
                        $stmt,
                        sprintf('xiaomi:%s:steps:%s', $sid, $bucketStart),
                        $sid,
                        RecordType::STEPS->value,
                        gmdate('Y-m-d H:i:s', $bucketStart),
                        gmdate('Y-m-d H:i:s', $bucketStart + self::BUCKET_SECONDS - 1),
                        $runAt,
                        $payload,
                    );
                    if (++$inBatch >= self::BATCH_SIZE) {
                        $this->connection->commit();
                        $this->connection->beginTransaction();
                        $inBatch = 0;
                    }
                }
                ++$emitted;
            }
        }

        if (!$dryRun) {
            $this->connection->commit();
        }

        return [$emitted, $emptySkipped];
    }

    /**
     * @param array<string, array<int, list<array{time: int, bpm: int}>>> $heartRateBuckets sid => [bucketStartEpoch => samples]
     */
    private function flushHeartRateBuckets(Statement $stmt, array $heartRateBuckets, string $runAt, bool $dryRun): int
    {
        $emitted = 0;
        $inBatch = 0;

        if (!$dryRun) {
            $this->connection->beginTransaction();
        }

        foreach ($heartRateBuckets as $sid => $buckets) {
            foreach ($buckets as $bucketStart => $samples) {
                usort($samples, static fn (array $a, array $b) => $a['time'] <=> $b['time']);

                $payload = [
                    'startTime' => gmdate('Y-m-d\TH:i:s\Z', $bucketStart),
                    'endTime' => gmdate('Y-m-d\TH:i:s\Z', $bucketStart + self::BUCKET_SECONDS - 1),
                    'samples' => array_map(
                        static fn (array $s) => ['time' => gmdate('Y-m-d\TH:i:s\Z', $s['time']), 'beatsPerMinute' => $s['bpm']],
                        $samples,
                    ),
                    'dataOrigin' => 'com.xiaomi.wearable',
                ];

                if (!$dryRun) {
                    $this->bindAndInsert(
                        $stmt,
                        sprintf('xiaomi:%s:heart_rate:%s', $sid, $bucketStart),
                        $sid,
                        RecordType::HEART_RATE->value,
                        gmdate('Y-m-d H:i:s', $bucketStart),
                        gmdate('Y-m-d H:i:s', $bucketStart + self::BUCKET_SECONDS - 1),
                        $runAt,
                        $payload,
                    );
                    if (++$inBatch >= self::BATCH_SIZE) {
                        $this->connection->commit();
                        $this->connection->beginTransaction();
                        $inBatch = 0;
                    }
                }
                ++$emitted;
            }
        }

        if (!$dryRun) {
            $this->connection->commit();
        }

        return $emitted;
    }

    /**
     * Marks every records_import row that isn't the best one in its
     * (type, start_time) group as deleted, so the same real-world window
     * doesn't get double-counted (double steps, double sleep, etc.) once
     * this lands in records. Duplicates arise because 790547985, hlth.gen
     * and xiaomisports_app are three historically-overlapping Xiaomi sync
     * pipelines that sometimes both cover the same window — with genuinely
     * different values, not just repeated noise, so this is a real
     * precedence call: prefer 790547985 (current account) > hlth.gen >
     * xiaomisports_app (oldest pipeline), per SOURCE_PRIORITY. Within the
     * same source (rare — e.g. two `sleep` rows sharing a bedtime but
     * different wake times), prefer the longer session, then the most
     * recently-inserted row, purely for determinism.
     *
     * Only 4 types are ever affected in practice (StepsRecord,
     * HeartRateRecord, SleepSessionRecord, resting_heart_rate) — every
     * other type has zero (type, start_time) collisions, so this is a
     * no-op for them either way.
     */
    private function dedupeWithinImport(): int
    {
        $cases = [];
        foreach (self::SOURCE_PRIORITY as $source => $rank) {
            $cases[] = sprintf('WHEN %s THEN %d', $this->connection->quote($source), $rank);
        }
        $sourceRankSql = sprintf('CASE source %s ELSE %d END', implode(' ', $cases), \count(self::SOURCE_PRIORITY) + 1);

        return $this->connection->executeStatement(sprintf(
            'UPDATE records_import
                SET deleted = 1
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id, ROW_NUMBER() OVER (
                            PARTITION BY type, start_time
                            ORDER BY %s, (julianday(end_time) - julianday(start_time)) DESC, id DESC
                        ) AS rn
                        FROM records_import
                    )
                    WHERE rn > 1
                )',
            $sourceRankSql,
        ));
    }

    /**
     * Blind conversion — see the SPO2 case doc in RecordType. Xiaomi's
     * {time, spo2} becomes {time, percentage, dataOrigin}; no ground truth
     * to verify field names against since the phone doesn't sync this yet.
     *
     * @return array{time: string, percentage: int, dataOrigin: string}|null
     */
    private function transformSpo2Payload(array $payload): ?array
    {
        if (!isset($payload['time'], $payload['spo2'])) {
            return null;
        }

        return [
            'time' => gmdate('Y-m-d\TH:i:s\Z', $payload['time']),
            'percentage' => $payload['spo2'],
            'dataOrigin' => 'com.xiaomi.wearable',
        ];
    }

    /**
     * @return array{startTime: string, endTime: string, title: null, stages: list<array{startTime: string, endTime: string, stage: string}>, dataOrigin: string}|null
     */
    private function transformSleepPayload(array $payload): ?array
    {
        if (!isset($payload['bedtime'], $payload['wake_up_time'])) {
            return null;
        }

        $stages = [];
        foreach ($payload['items'] ?? [] as $item) {
            if (!isset($item['start_time'], $item['end_time'], $item['state'])) {
                return null;
            }
            $stage = self::SLEEP_STATE_MAP[$item['state']] ?? null;
            if (null === $stage) {
                return null;
            }
            $stages[] = [
                'startTime' => gmdate('Y-m-d\TH:i:s\Z', $item['start_time']),
                'endTime' => gmdate('Y-m-d\TH:i:s\Z', $item['end_time']),
                'stage' => $stage,
            ];
        }

        return [
            'startTime' => gmdate('Y-m-d\TH:i:s\Z', $payload['bedtime']),
            'endTime' => gmdate('Y-m-d\TH:i:s\Z', $payload['wake_up_time']),
            'title' => null,
            'stages' => $stages,
            'dataOrigin' => 'com.xiaomi.wearable',
        ];
    }
}
