<?php

namespace App\Tests\Command;

use App\Repository\RecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ConsolidateRecordsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordRepository $records;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->records = self::getContainer()->get(RecordRepository::class);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:consolidate:records'));
    }

    public function testDryRunReportsWithoutChangingAnything(): void
    {
        $this->insertTombstone('tomb-1');
        $this->insertLocation('loc-1', $start = new \DateTimeImmutable('2026-08-29T10:00:00Z'));
        $this->insertLocation('loc-2', $start); // exact duplicate: same type + start_time

        $before = $this->records->countAll();

        $this->tester->execute(['--dry-run' => true]);

        self::assertSame($before, $this->records->countAll());
        self::assertFalse($this->records->findOneByRecordUid('loc-2')->isDeleted());
        self::assertStringContainsString('Tombstones: 1 would be hard-deleted', $this->tester->getDisplay());
        self::assertStringContainsString('Exact duplicates: 1 would be marked deleted', $this->tester->getDisplay());
    }

    public function testRealRunHardDeletesTombstonesButOnlySoftDeletesExactDuplicates(): void
    {
        $this->insertTombstone('tomb-1');
        $start = new \DateTimeImmutable('2026-08-29T10:00:00Z');
        $this->insertLocation('loc-1', $start);
        $this->insertLocation('loc-2', $start);

        $this->tester->execute([]);

        // Genuine tombstone: physically gone, safe (see RecordRepository::hardDeleteTombstones()).
        self::assertNull($this->records->findOneByRecordUid('tomb-1'));

        // Exact duplicate: soft-deleted only, recordUid preserved so a
        // resync can't recreate it as a "new" row (see markDeleted()).
        self::assertSame(2, $this->records->countAll());
        self::assertFalse($this->records->findOneByRecordUid('loc-1')->isDeleted());
        self::assertTrue($this->records->findOneByRecordUid('loc-2')->isDeleted());
    }

    public function testRealRunSoftDeletesLowerPriorityOverlappingStepsWithoutTouchingTheWinner(): void
    {
        $day = new \DateTimeImmutable('2026-08-29T00:00:00Z');
        $this->records->upsertByRecordUid('steps-shealth', 'health_connect', 'StepsRecord', $day, $day->modify('+23 hours'), $day, $day, false, [
            'count' => 9455,
            'dataOrigin' => 'com.sec.android.app.shealth',
        ]);
        $this->records->upsertByRecordUid('steps-hc', 'health_connect', 'StepsRecord', $day->modify('+1 hour'), $day->modify('+2 hours'), $day, $day, false, [
            'count' => 500,
            'dataOrigin' => 'com.android.healthconnect.phone.sensor',
        ]);
        $this->em->flush();

        $this->tester->execute([]);

        $shealth = $this->records->findOneByRecordUid('steps-shealth');
        self::assertNotNull($shealth);
        self::assertTrue($shealth->isDeleted());
        self::assertSame(9455, $shealth->getPayload()['count']); // untouched, not zeroed/merged

        $hc = $this->records->findOneByRecordUid('steps-hc');
        self::assertFalse($hc->isDeleted());
        self::assertSame(500, $hc->getPayload()['count']); // untouched, not mutated into a sum
    }

    private function insertTombstone(string $uid): void
    {
        $now = new \DateTimeImmutable();
        $this->records->upsertByRecordUid($uid, 'health_connect', 'Ping', $now, null, $now, $now, true, []);
        $this->em->flush();
    }

    private function insertLocation(string $uid, \DateTimeImmutable $startTime): void
    {
        $this->records->upsertByRecordUid($uid, 'location', 'LocationFix', $startTime, null, $startTime, $startTime, false, [
            'latitude' => 41.0,
            'longitude' => 20.0,
        ]);
        $this->em->flush();
    }
}
