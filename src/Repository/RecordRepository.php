<?php

namespace App\Repository;

use App\Entity\Record;
use App\Enum\RecordType;
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
     * Matches both type-string variants for types affected by the
     * 28.08.2026 self-heal (see RecordType::variants()), so a caller
     * passing either the legacy or the current form still sees every live
     * row for that concept, not just the ones spelled the way it asked.
     *
     * @return Record[]
     */
    public function findByTypeSince(string $type, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.type IN (:types)')
            ->andWhere('r.startTime >= :since')
            ->andWhere('r.deleted = false')
            ->setParameter('types', RecordType::variants($type))
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every live row whose type is one of $types, oldest first — the
     * whole-history candidate set ConsolidationEngine scans for
     * duplicates/overlaps during a Phase-1 backlog pass
     * (app:consolidate:records).
     *
     * @param string[] $types
     * @return Record[]
     */
    public function findLiveByTypeIn(array $types): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.type IN (:types)')
            ->andWhere('r.deleted = false')
            ->setParameter('types', $types)
            ->orderBy('r.startTime', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Live rows of $types whose startTime falls in [$from, $to] — the
     * bounded "neighborhood" query ConsolidateMessageHandler uses instead
     * of rescanning a whole type's history on every tick.
     *
     * @param string[] $types
     * @return Record[]
     */
    public function findLiveByTypeInRange(array $types, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.type IN (:types)')
            ->andWhere('r.deleted = false')
            ->andWhere('r.startTime >= :from')
            ->andWhere('r.startTime <= :to')
            ->setParameter('types', $types)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.startTime', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Live rows synced since $cursor — what a scheduled consolidation tick
     * needs to check against their neighbors, instead of every row ever.
     *
     * @return Record[]
     */
    public function findByReceivedAtAfter(\DateTimeImmutable $cursor): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.receivedAt > :cursor')
            ->andWhere('r.deleted = false')
            ->setParameter('cursor', $cursor)
            ->orderBy('r.receivedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Real DELETE — safe specifically because a genuine tombstone
     * (payload == {"deleted": true}, set by the phone itself) carries no
     * live data to lose: if the phone ever re-sends that exact recordUid as
     * a tombstone again, upsertByRecordUid just re-inserts an equivalent
     * deleted=true row, which the next pass hard-deletes again. Contrast
     * with markDeleted() below, used for consolidation's own
     * dedup/overlap decisions, which must stay reversible — see that
     * method's docblock.
     */
    public function hardDeleteTombstones(): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.deleted = true')
            ->getQuery()
            ->execute();
    }

    /**
     * Soft-delete only — corrected 29.08.2026 after hard-deleting
     * consolidation's own losing rows broke the ingest contract's
     * "idempotent upsert-by-recordUid" guarantee (docs/04-pc-sync-api.md):
     * recordUid is phone-generated and stable, so if a hard-deleted row's
     * recordUid was ever re-synced (e.g. after a phone app reinstall
     * re-imports Health Connect history), upsertByRecordUid wouldn't find
     * it and would INSERT it as brand new — resurrecting exactly the
     * duplicate consolidation had removed, with no way to tell it apart
     * from genuinely new data. Marking deleted=true instead means a
     * resync's upsert finds the existing row and updates it in place; if
     * that revives a genuine duplicate (flips it back to deleted=false),
     * the next consolidation pass just re-marks it, same outcome, no data
     * loss or double counting either way.
     *
     * Takes the entities themselves, not just their ids, so it can also set
     * ->setDeleted(true) on each in-memory object after the bulk UPDATE —
     * like the bulk DELETE it replaced, a DQL bulk UPDATE bypasses the
     * identity map, so any already-loaded copy of a row (e.g. the very
     * candidate list a caller just resolved losers from) would otherwise
     * keep reporting deleted=false in-process even though the DB is
     * already correct.
     *
     * @param Record[] $records
     */
    public function markDeleted(array $records): int
    {
        if ($records === []) {
            return 0;
        }

        $ids = array_map(static fn (Record $r) => $r->getId(), $records);
        $updated = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $updated += $this->createQueryBuilder('r')
                ->update()
                ->set('r.deleted', ':deleted')
                ->andWhere('r.id IN (:ids)')
                ->setParameter('deleted', true)
                ->setParameter('ids', $chunk)
                ->getQuery()
                ->execute();
        }

        foreach ($records as $record) {
            $record->setDeleted(true);
        }

        return $updated;
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
