<?php

namespace App\Tests\Repository;

use App\Repository\RecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RecordRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(RecordRepository::class);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUpsertInsertsNewRecord(): void
    {
        $this->upsert('uid-1', ['bpm' => 60]);
        $this->em->flush();

        self::assertSame(1, $this->repository->countAll());

        $record = $this->repository->findOneByRecordUid('uid-1');
        self::assertNotNull($record);
        self::assertSame(['bpm' => 60], $record->getPayload());
        self::assertFalse($record->isDeleted());
    }

    public function testUpsertByExistingUidReplacesPayloadWithoutDuplicating(): void
    {
        $this->upsert('uid-1', ['bpm' => 60]);
        $this->em->flush();

        $this->upsert('uid-1', ['bpm' => 72]);
        $this->em->flush();

        self::assertSame(1, $this->repository->countAll());
        self::assertSame(['bpm' => 72], $this->repository->findOneByRecordUid('uid-1')->getPayload());
    }

    public function testUpsertWithDeletedTombstonesRecordInsteadOfRemovingIt(): void
    {
        $this->upsert('uid-1', ['bpm' => 60]);
        $this->em->flush();

        $this->upsert('uid-1', ['deleted' => true], deleted: true);
        $this->em->flush();

        self::assertSame(1, $this->repository->countAll());
        $record = $this->repository->findOneByRecordUid('uid-1');
        self::assertTrue($record->isDeleted());
        self::assertSame(['deleted' => true], $record->getPayload());
    }

    private function upsert(string $recordUid, array $payload, bool $deleted = false): void
    {
        $now = new \DateTimeImmutable();
        $this->repository->upsertByRecordUid(
            $recordUid,
            'health_connect',
            'HeartRate',
            $now,
            null,
            $now,
            $now,
            $deleted,
            $payload,
        );
    }
}
