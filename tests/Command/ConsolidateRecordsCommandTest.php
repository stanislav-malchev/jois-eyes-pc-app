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

    public function testDryRunReportsWithoutDeletingAnything(): void
    {
        $this->insertTombstone('tomb-1');
        $this->insertLocation('loc-1', $start = new \DateTimeImmutable('2026-08-29T10:00:00Z'));
        $this->insertLocation('loc-2', $start); // exact duplicate: same type + start_time

        $before = $this->records->countAll();

        $this->tester->execute(['--dry-run' => true]);

        self::assertSame($before, $this->records->countAll());
        self::assertStringContainsString('Tombstones: 1 would be hard-deleted', $this->tester->getDisplay());
        self::assertStringContainsString('Exact duplicates: 1 would be hard-deleted', $this->tester->getDisplay());
    }

    public function testRealRunHardDeletesTombstonesAndExactDuplicates(): void
    {
        $this->insertTombstone('tomb-1');
        $start = new \DateTimeImmutable('2026-08-29T10:00:00Z');
        $this->insertLocation('loc-1', $start);
        $this->insertLocation('loc-2', $start);

        $this->tester->execute([]);

        self::assertSame(1, $this->records->countAll());
        self::assertNotNull($this->records->findOneByRecordUid('loc-1'));
        self::assertNull($this->records->findOneByRecordUid('loc-2'));
        self::assertNull($this->records->findOneByRecordUid('tomb-1'));
    }

    public function testRealRunDropsLowerPriorityOverlappingSteps(): void
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

        self::assertNull($this->records->findOneByRecordUid('steps-shealth'));
        self::assertNotNull($this->records->findOneByRecordUid('steps-hc'));
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
