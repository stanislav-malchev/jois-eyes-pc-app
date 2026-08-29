<?php

namespace App\Tests\Service\Consolidation;

use App\Repository\RecordRepository;
use App\Service\Consolidation\DailyStepsConsolidator;
use App\Service\Consolidation\DataOriginPriority;
use App\Service\Normalization\RecordMetricsExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Priority here matches the real wiring (config/services.yaml): Samsung
 * Health wins for Steps — confirmed 29.08.2026 against the phone's own
 * Health Connect app that it's the band's data, not the phone's own
 * on-device sensor (com.android.healthconnect.phone.*).
 */
class DailyStepsConsolidatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordRepository $records;
    private DailyStepsConsolidator $consolidator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->records = self::getContainer()->get(RecordRepository::class);
        $this->consolidator = new DailyStepsConsolidator(new DataOriginPriority(['com.sec.android.app.shealth' => 10]), $this->records);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testResolveDaySumsOnlyTheWinningTier(): void
    {
        // Real 29.08.2026 finding: Health Connect's own app confirmed
        // Samsung Health's 9,455 as correct for this day, not the
        // phone-bridge's 8,147.
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $samsung = $this->insertSteps('shealth', 9455, $day, $day->modify('+23 hours'), 'com.sec.android.app.shealth');
        $hc1 = $this->insertSteps('hc-1', 5000, $day->modify('+1 hour'), $day->modify('+2 hours'), 'com.android.healthconnect.phone.sensor');
        $hc2 = $this->insertSteps('hc-2', 3147, $day->modify('+3 hours'), $day->modify('+4 hours'), 'com.android.healthconnect.phone.sensor');

        $resolved = $this->consolidator->resolveDay([$samsung, $hc1, $hc2]);

        self::assertSame(9455, $resolved['total']);
        self::assertSame([$samsung], $resolved['winners']);
        self::assertEqualsCanonicalizing([$hc1, $hc2], $resolved['losers']);
    }

    public function testResolveDaySumsSameTierBurstsEvenWithoutAnyConflict(): void
    {
        // No competing app this day — still resolves to one total, per
        // Stan's "steps we can all merge into one row per day".
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $a = $this->insertSteps('shealth-1', 100, $day, $day->modify('+30 minutes'), 'com.sec.android.app.shealth');
        $b = $this->insertSteps('shealth-2', 250, $day->modify('+1 hour'), $day->modify('+90 minutes'), 'com.sec.android.app.shealth');

        $resolved = $this->consolidator->resolveDay([$a, $b]);

        self::assertSame(350, $resolved['total']);
        self::assertEqualsCanonicalizing([$a, $b], $resolved['winners']);
        self::assertSame([], $resolved['losers']);
    }

    public function testResolveDayDedupesExactRetryDuplicatesWithinTheWinningTier(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $original = $this->insertSteps('shealth', 200, $day, $day->modify('+30 minutes'), 'com.sec.android.app.shealth');
        $retry = $this->insertSteps('shealth-retry', 200, $day, $day->modify('+30 minutes'), 'com.sec.android.app.shealth');

        $resolved = $this->consolidator->resolveDay([$original, $retry]);

        self::assertSame(200, $resolved['total']);
        self::assertSame([$original], $resolved['winners']);
        self::assertSame([$retry], $resolved['losers']);
    }

    public function testConsolidateDaySoftDeletesLosersAndLeavesWinnersUntouched(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $this->insertSteps('shealth', 9455, $day, $day->modify('+23 hours'), 'com.sec.android.app.shealth');
        $this->insertSteps('hc-1', 5000, $day->modify('+1 hour'), $day->modify('+2 hours'), 'com.android.healthconnect.phone.sensor');
        $this->insertSteps('hc-2', 3147, $day->modify('+3 hours'), $day->modify('+4 hours'), 'com.android.healthconnect.phone.sensor');

        $records = $this->records->findLiveByTypeIn(['StepsRecord']);
        $this->consolidator->consolidateDay($records, dryRun: false);

        // Winner: untouched, still live, original count intact.
        $samsung = $this->records->findOneByRecordUid('shealth');
        self::assertFalse($samsung->isDeleted());
        self::assertSame(9455, $samsung->getPayload()['count']);

        // Losers: soft-deleted, not gone — recordUid still blocks a resync
        // from re-inserting them as "new" rows.
        $hc1 = $this->records->findOneByRecordUid('hc-1');
        $hc2 = $this->records->findOneByRecordUid('hc-2');
        self::assertNotNull($hc1);
        self::assertTrue($hc1->isDeleted());
        self::assertSame(5000, $hc1->getPayload()['count']);
        self::assertNotNull($hc2);
        self::assertTrue($hc2->isDeleted());

        // Nothing physically removed.
        self::assertCount(3, $this->records->findBy(['recordUid' => ['shealth', 'hc-1', 'hc-2']]));
    }

    public function testConsolidateDayIsANoOpWhenTheDayIsAlreadyOneRow(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $this->insertSteps('shealth', 500, $day, $day->modify('+1 hour'), 'com.sec.android.app.shealth');

        $records = $this->records->findLiveByTypeIn(['StepsRecord']);
        $markedIds = $this->consolidator->consolidateDay($records, dryRun: false);

        self::assertSame([], $markedIds);
        self::assertFalse($this->records->findOneByRecordUid('shealth')->isDeleted());
    }

    public function testConsolidateDayDryRunChangesNothing(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $this->insertSteps('shealth', 9455, $day, $day->modify('+23 hours'), 'com.sec.android.app.shealth');
        $this->insertSteps('hc-1', 5000, $day->modify('+1 hour'), $day->modify('+2 hours'), 'com.android.healthconnect.phone.sensor');

        $records = $this->records->findLiveByTypeIn(['StepsRecord']);
        $markedIds = $this->consolidator->consolidateDay($records, dryRun: true);

        self::assertCount(1, $markedIds);
        self::assertFalse($this->records->findOneByRecordUid('hc-1')->isDeleted());
    }

    private function insertSteps(string $uid, int $count, \DateTimeImmutable $start, \DateTimeImmutable $end, string $dataOrigin): \App\Entity\Record
    {
        $record = $this->records->upsertByRecordUid($uid, 'health_connect', 'StepsRecord', $start, $end, $start, $start, false, [
            'count' => $count,
            'dataOrigin' => $dataOrigin,
        ]);
        // resolveDay() now reads metricValue/dataOrigin columns, not
        // payload — mirror real ingest, which always runs the extractor.
        (new RecordMetricsExtractor())->extract($record);
        $this->em->flush();

        return $record;
    }
}
