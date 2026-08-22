<?php

namespace App\Repository;

use App\Entity\Record;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Record>
 */
class RecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Record::class);
    }

    public function findOneByRecordUid(string $recordUid): ?Record
    {
        return $this->findOneBy(['recordUid' => $recordUid]);
    }

    /**
     * Insert-or-replace by recordUid: the idempotency primitive the ingest
     * endpoint relies on. Does not flush; caller controls the transaction
     * boundary so a whole batch can be committed at once.
     */
    public function upsertByRecordUid(
        string $recordUid,
        string $source,
        string $type,
        \DateTimeImmutable $startTime,
        ?\DateTimeImmutable $endTime,
        \DateTimeImmutable $ingestedAt,
        \DateTimeImmutable $receivedAt,
        bool $deleted,
        array $payload,
    ): Record {
        $record = $this->findOneByRecordUid($recordUid);
        if (!$record) {
            $record = new Record($recordUid);
            $this->getEntityManager()->persist($record);
        }

        $record
            ->setSource($source)
            ->setType($type)
            ->setStartTime($startTime)
            ->setEndTime($endTime)
            ->setIngestedAt($ingestedAt)
            ->setReceivedAt($receivedAt)
            ->setDeleted($deleted)
            ->setPayload($payload);

        return $record;
    }

    /**
     * @return Record[]
     */
    public function findByTypeSince(string $type, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.type = :type')
            ->andWhere('r.startTime >= :since')
            ->andWhere('r.deleted = false')
            ->setParameter('type', $type)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    public function findLatestBySource(string $source): ?Record
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.source = :source')
            ->andWhere('r.deleted = false')
            ->setParameter('source', $source)
            ->orderBy('r.startTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDeleted(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.deleted = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.receivedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, array{value: string, count: int}>
     */
    public function countGroupedBy(string $field): array
    {
        return $this->createQueryBuilder('r')
            ->select(sprintf('r.%s AS value', $field), 'COUNT(r.id) AS count')
            ->groupBy(sprintf('r.%s', $field))
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    public function latestReceivedAt(): ?\DateTimeImmutable
    {
        $value = $this->getEntityManager()->getConnection()
            ->executeQuery('SELECT MAX(received_at) FROM records')
            ->fetchOne();

        return $value ? new \DateTimeImmutable($value) : null;
    }
}
