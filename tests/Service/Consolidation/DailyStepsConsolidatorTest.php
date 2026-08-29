<?php

namespace App\Tests\Service\Consolidation;

use App\Repository\RecordRepository;
use App\Service\Consolidation\DailyStepsConsolidator;
use App\Service\Consolidation\DataOriginPriority;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
        $this->consolidator = new DailyStepsConsolidator(new DataOriginPriority(), $this->records);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testResolveDaySumsOnlyTheWinningTier(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $samsung = $this->insertSteps('shealth', 9455, $day, $day->modify('+23 hours'), 'com.sec.android.app.shealth');
        $hc1 = $this->insertSteps('hc-1', 5000, $day->modify('+1 hour'), $day->modify('+2 hours'), 'com.android.healthconnect.phone.sensor');
        $hc2 = $this->insertSteps('hc-2', 3147, $day->modify('+3 hours'), $day->modify('+4 hours'), 'com.android.healthconnect.phone.sensor');

        $resolved = $this->consolidator->resolveDay([$samsung, $hc1, $hc2]);

        self::assertSame(8147, $resolved['total']);
        self::assertEqualsCanonicalizing([$hc1, $hc2], $resolved['winners']);
        self::assertSame([$samsung], $resolved['losers']);
    }

    public function testResolveDaySumsSameTierBurstsEvenWithoutAnyConflict(): void
    {
        // No Samsung Health row at all this day — still resolves to one
        // total, per Stan's "steps we can all merge into one row per day".
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $hc1 = $this->insertSteps('hc-1', 100, $day, $day->modify('+30 minutes'), 'com.android.healthconnect.phone.sensor');
        $hc2 = $this->insertSteps('hc-2', 250, $day->modify('+1 hour'), $day->modify('+90 minutes'), 'com.android.healthconnect.phone.sensor');

        $resolved = $this->consolidator->resolveDay([$hc1, $hc2]);

        self::assertSame(350, $resolved['total']);
        self::assertEqualsCanonicalizing([$hc1, $hc2], $resolved['winners']);
        self::assertSame([], $resolved['losers']);
    }

    public function testResolveDayDedupesExactRetryDuplicatesWithinTheWinningTier(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $original = $this->insertSteps('hc-1', 200, $day, $day->modify('+30 minutes'), 'com.android.healthconnect.phone.sensor');
        $retry = $this->insertSteps('hc-1-retry', 200, $day, $day->modify('+30 minutes'), 'com.android.healthconnect.phone.sensor');

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

        // Winners: untouched, still live, original individual counts intact.
        $hc1 = $this->records->findOneByRecordUid('hc-1');
        $hc2 = $this->records->findOneByRecordUid('hc-2');
        self::assertFalse($hc1->isDeleted());
        self::assertSame(5000, $hc1->getPayload()['count']);
        self::assertFalse($hc2->isDeleted());
        self::assertSame(3147, $hc2->getPayload()['count']);

        // Loser: soft-deleted, not gone — recordUid still blocks a resync
        // from re-inserting it as a "new" row.
        $samsung = $this->records->findOneByRecordUid('shealth');
        self::assertNotNull($samsung);
        self::assertTrue($samsung->isDeleted());
        self::assertSame(9455, $samsung->getPayload()['count']);

        // Nothing physically removed.
        self::assertCount(3, $this->records->findBy(['recordUid' => ['shealth', 'hc-1', 'hc-2']]));
    }

    public function testConsolidateDayIsANoOpWhenTheDayIsAlreadyOneRow(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $this->insertSteps('hc-1', 500, $day, $day->modify('+1 hour'), 'com.android.healthconnect.phone.sensor');

        $records = $this->records->findLiveByTypeIn(['StepsRecord']);
        $markedIds = $this->consolidator->consolidateDay($records, dryRun: false);

        self::assertSame([], $markedIds);
        self::assertFalse($this->records->findOneByRecordUid('hc-1')->isDeleted());
    }

    public function testConsolidateDayDryRunChangesNothing(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $this->insertSteps('shealth', 9455, $day, $day->modify('+23 hours'), 'com.sec.android.app.shealth');
        $this->insertSteps('hc-1', 5000, $day->modify('+1 hour'), $day->modify('+2 hours'), 'com.android.healthconnect.phone.sensor');

        $records = $this->records->findLiveByTypeIn(['StepsRecord']);
        $markedIds = $this->consolidator->consolidateDay($records, dryRun: true);

        self::assertCount(1, $markedIds);
        self::assertFalse($this->records->findOneByRecordUid('shealth')->isDeleted());
    }

    private function insertSteps(string $uid, int $count, \DateTimeImmutable $start, \DateTimeImmutable $end, string $dataOrigin): \App\Entity\Record
    {
        $record = $this->records->upsertByRecordUid($uid, 'health_connect', 'StepsRecord', $start, $end, $start, $start, false, [
            'count' => $count,
            'dataOrigin' => $dataOrigin,
        ]);
        $this->em->flush();

        return $record;
    }
}
