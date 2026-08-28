<?php

namespace App\Tests\Service\Consolidation;

use App\Entity\Record;
use App\Service\Consolidation\DataOriginPriority;
use App\Service\Consolidation\OverlapResolver;
use PHPUnit\Framework\TestCase;

class OverlapResolverTest extends TestCase
{
    private OverlapResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new OverlapResolver(new DataOriginPriority());
    }

    public function testNonOverlappingRecordsAreAllKept(): void
    {
        $a = $this->record(1, '2026-08-29T00:00:00Z', '2026-08-29T01:00:00Z');
        $b = $this->record(2, '2026-08-29T02:00:00Z', '2026-08-29T03:00:00Z');

        $result = $this->resolver->resolve([$a, $b]);

        self::assertSame([$a, $b], $result['keep']);
        self::assertSame([], $result['drop']);
        self::assertSame([], $result['flagged']);
    }

    public function testHealthConnectWinsOverOverlappingOtherApp(): void
    {
        $samsungRollup = $this->record(1, '2026-08-29T00:00:00Z', '2026-08-29T23:00:00Z', ['count' => 9455, 'dataOrigin' => 'com.sec.android.app.shealth']);
        $healthConnectBurst = $this->record(2, '2026-08-29T01:00:00Z', '2026-08-29T02:00:00Z', ['count' => 500, 'dataOrigin' => 'com.android.healthconnect.phone.sensor']);

        $result = $this->resolver->resolve([$samsungRollup, $healthConnectBurst]);

        self::assertSame([$healthConnectBurst], $result['keep']);
        self::assertSame([$samsungRollup], $result['drop']);
        self::assertSame([], $result['flagged']);
    }

    public function testNonOverlappingLowerPriorityRecordIsKeptDespiteBeingInTheSameSweepGroup(): void
    {
        // Both fall in one sweep-line group because the HC record spans the
        // whole window, but the Samsung record's own window doesn't overlap it.
        $healthConnect = $this->record(1, '2026-08-29T00:00:00Z', '2026-08-29T23:00:00Z', ['dataOrigin' => 'com.android.healthconnect.phone.sensor']);
        $samsungLater = $this->record(2, '2026-08-30T00:00:00Z', '2026-08-30T01:00:00Z', ['dataOrigin' => 'com.sec.android.app.shealth']);

        $result = $this->resolver->resolve([$healthConnect, $samsungLater]);

        self::assertContains($samsungLater, $result['keep']);
        self::assertNotContains($samsungLater, $result['drop']);
    }

    public function testSubsetSamplesAreDroppedWhenDataOriginsMatch(): void
    {
        $wide = $this->record(1, '2026-08-29T00:00:00Z', '2026-08-29T01:00:00Z', [
            'dataOrigin' => 'com.sec.android.app.shealth',
            'samples' => [['time' => 't1', 'beatsPerMinute' => 60], ['time' => 't2', 'beatsPerMinute' => 62]],
        ]);
        $subset = $this->record(2, '2026-08-29T00:10:00Z', '2026-08-29T00:20:00Z', [
            'dataOrigin' => 'com.sec.android.app.shealth',
            'samples' => [['time' => 't1', 'beatsPerMinute' => 60]],
        ]);

        $result = $this->resolver->resolve([$wide, $subset]);

        self::assertSame([$wide], $result['keep']);
        self::assertSame([$subset], $result['drop']);
    }

    public function testEqualPriorityNonSubsetOverlapIsFlaggedAndKeptNotMerged(): void
    {
        $appA = $this->record(1, '2026-08-29T00:00:00Z', '2026-08-29T01:00:00Z', ['count' => 100, 'dataOrigin' => 'com.example.fitness_a']);
        $appB = $this->record(2, '2026-08-29T00:30:00Z', '2026-08-29T01:30:00Z', ['count' => 80, 'dataOrigin' => 'com.example.fitness_b']);

        $result = $this->resolver->resolve([$appA, $appB]);

        self::assertEqualsCanonicalizing([$appA, $appB], $result['keep']);
        self::assertSame([], $result['drop']);
        self::assertCount(1, $result['flagged']);
        self::assertEqualsCanonicalizing([$appA, $appB], $result['flagged'][0]);
    }

    private function record(int $id, string $start, ?string $end, array $payload = []): Record
    {
        $record = new Record('rec-'.$id);
        $record
            ->setType('StepsRecord')
            ->setSource('health_connect')
            ->setStartTime(new \DateTimeImmutable($start))
            ->setEndTime($end !== null ? new \DateTimeImmutable($end) : null)
            ->setIngestedAt(new \DateTimeImmutable($start))
            ->setReceivedAt(new \DateTimeImmutable($start))
            ->setDeleted(false)
            ->setPayload($payload);

        $reflection = new \ReflectionProperty(Record::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($record, $id);

        return $record;
    }
}
