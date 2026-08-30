<?php

namespace App\Tests\Backdoor;

use App\Backdoor\LiveVitalsResolver;
use App\Backdoor\SnapshotClient;
use App\Repository\RecordRepository;
use App\Service\Consolidation\DailyStepsConsolidator;
use App\Service\Consolidation\DataOriginPriority;
use App\Service\Normalization\RecordMetricsExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LiveVitalsResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordRepository $records;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->records = self::getContainer()->get(RecordRepository::class);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testGetLocationPrefersPhoneSnapshotWhenAvailable(): void
    {
        $resolver = $this->resolverWithSnapshot([
            'location' => ['ts' => 1_700_000_000_000, 'lat' => 43.1, 'lon' => 28.2, 'accuracy_m' => 10.0, 'altitude_m' => 5.0],
            'activity' => ['steps_today' => null, 'steps_ts' => null],
            'wearables' => ['band' => ['hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $location = $resolver->getLocation();

        self::assertSame(LiveVitalsResolver::SOURCE_PHONE_SNAPSHOT, $location['source']);
        self::assertSame(43.1, $location['latitude']);
        self::assertSame(28.2, $location['longitude']);
        self::assertSame(1_700_000_000, $location['timestamp']->getTimestamp());
    }

    public function testGetLocationFallsBackToStoredRecordWhenSnapshotUnreachable(): void
    {
        $this->insertLocationRecord(41.0, 20.0);
        $resolver = $this->resolverWithUnreachableSnapshot();

        $location = $resolver->getLocation();

        self::assertSame(LiveVitalsResolver::SOURCE_STORED_RECORDS, $location['source']);
        self::assertSame(41.0, $location['latitude']);
        self::assertSame(20.0, $location['longitude']);
    }

    public function testGetLocationReturnsNullWhenNothingIsAvailable(): void
    {
        $resolver = $this->resolverWithUnreachableSnapshot();

        self::assertNull($resolver->getLocation());
    }

    public function testGetHeartRatePrefersPhoneSnapshotWhenAvailable(): void
    {
        $resolver = $this->resolverWithSnapshot([
            'location' => ['ts' => null, 'lat' => null, 'lon' => null],
            'activity' => ['steps_today' => null, 'steps_ts' => null],
            'wearables' => ['band' => ['hr_bpm' => 71, 'hr_ts' => 1_700_000_000_000]],
        ]);

        $heartRate = $resolver->getHeartRate();

        self::assertSame(LiveVitalsResolver::SOURCE_PHONE_SNAPSHOT, $heartRate['source']);
        self::assertSame(71, $heartRate['bpm']);
    }

    public function testGetHeartRateFallsBackToStoredRecordWhenSnapshotHasNoReading(): void
    {
        $this->insertHeartRateRecord(66);
        $resolver = $this->resolverWithSnapshot([
            'location' => ['ts' => null, 'lat' => null, 'lon' => null],
            'activity' => ['steps_today' => null, 'steps_ts' => null],
            'wearables' => ['band' => ['hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $heartRate = $resolver->getHeartRate();

        self::assertSame(LiveVitalsResolver::SOURCE_STORED_RECORDS, $heartRate['source']);
        self::assertSame(66, $heartRate['bpm']);
    }

    public function testGetStepsTodayPrefersPhoneSnapshotWhenDatedToday(): void
    {
        $startOfDayUtc = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $tsToday = ($startOfDayUtc->getTimestamp() + 3600) * 1000;

        $resolver = $this->resolverWithSnapshot([
            'location' => ['ts' => null, 'lat' => null, 'lon' => null],
            'activity' => ['steps_today' => 4200, 'steps_ts' => $tsToday],
            'wearables' => ['band' => ['hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $steps = $resolver->getStepsToday($startOfDayUtc);

        self::assertSame(LiveVitalsResolver::SOURCE_PHONE_SNAPSHOT, $steps['source']);
        self::assertSame(4200, $steps['steps']);
    }

    public function testGetStepsTodayIgnoresSnapshotFromBeforeTodayAndSumsStoredRecords(): void
    {
        $startOfDayUtc = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $tsYesterday = ($startOfDayUtc->getTimestamp() - 3600) * 1000;

        $this->insertStepsRecord(500, $startOfDayUtc->modify('+1 hour'));
        $this->insertStepsRecord(300, $startOfDayUtc->modify('+2 hours'));

        $resolver = $this->resolverWithSnapshot([
            'location' => ['ts' => null, 'lat' => null, 'lon' => null],
            'activity' => ['steps_today' => 9999, 'steps_ts' => $tsYesterday],
            'wearables' => ['band' => ['hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $steps = $resolver->getStepsToday($startOfDayUtc);

        self::assertSame(LiveVitalsResolver::SOURCE_STORED_RECORDS, $steps['source']);
        self::assertSame(800, $steps['steps']);
    }

    public function testGetStepsTodayResolvesOverlappingAppsInsteadOfDoubleCounting(): void
    {
        $startOfDayUtc = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        // Samsung Health's whole-day rollup and the phone's own narrower
        // on-device-sensor bursts covering (part of) the same window.
        // Confirmed 29.08.2026 against the phone's own Health Connect app,
        // day by day, that Samsung Health (fed by the band) is correct and
        // the phone-bridge bursts are the redundant/wrong reading.
        $this->insertStepsRecord(9455, $startOfDayUtc, $startOfDayUtc->modify('+23 hours'), 'com.sec.android.app.shealth');
        $this->insertStepsRecord(5000, $startOfDayUtc->modify('+1 hour'), $startOfDayUtc->modify('+2 hours'), 'com.android.healthconnect.phone.sensor');
        $this->insertStepsRecord(3147, $startOfDayUtc->modify('+3 hour'), $startOfDayUtc->modify('+4 hours'), 'com.android.healthconnect.phone.sensor');

        $resolver = $this->resolverWithUnreachableSnapshot();

        $steps = $resolver->getStepsToday($startOfDayUtc);

        self::assertSame(LiveVitalsResolver::SOURCE_STORED_RECORDS, $steps['source']);
        self::assertSame(9455, $steps['steps']);
    }

    private function resolverWithSnapshot(array $snapshotBody): LiveVitalsResolver
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode($snapshotBody + ['api_version' => 1])));

        return new LiveVitalsResolver(new SnapshotClient($httpClient, 'http://phone.test:8788'), $this->records, new DailyStepsConsolidator(new DataOriginPriority(['com.sec.android.app.shealth' => 10]), $this->records));
    }

    private function resolverWithUnreachableSnapshot(): LiveVitalsResolver
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        return new LiveVitalsResolver(new SnapshotClient($httpClient, 'http://phone.test:8788'), $this->records, new DailyStepsConsolidator(new DataOriginPriority(['com.sec.android.app.shealth' => 10]), $this->records));
    }

    private function insertLocationRecord(float $lat, float $lon): void
    {
        $now = new \DateTimeImmutable();
        $this->records->upsertByRecordUid('loc-1', 'location', 'LocationFix', $now, null, $now, $now, false, [
            'latitude' => $lat,
            'longitude' => $lon,
        ]);
        $this->em->flush();
    }

    private function insertHeartRateRecord(int $bpm): void
    {
        $now = new \DateTimeImmutable();
        $this->records->upsertByRecordUid('hr-1', 'health_connect', 'HeartRateRecord', $now, null, $now, $now, false, [
            'samples' => [['beatsPerMinute' => $bpm, 'time' => $now->format(\DateTimeInterface::ATOM)]],
        ]);
        $this->em->flush();
    }

    private function insertStepsRecord(int $count, \DateTimeImmutable $startTime, ?\DateTimeImmutable $endTime = null, ?string $dataOrigin = null): void
    {
        static $i = 0;
        ++$i;
        $payload = ['count' => $count];
        if ($dataOrigin !== null) {
            $payload['dataOrigin'] = $dataOrigin;
        }
        $record = $this->records->upsertByRecordUid("steps-$i", 'health_connect', 'StepsRecord', $startTime, $endTime, $startTime, $startTime, false, $payload);
        // DailyStepsConsolidator::resolveDay() (used by getStepsToday()'s
        // DB fallback) now reads metricValue/dataOrigin columns, not
        // payload — mirror real ingest, which always runs the extractor.
        self::getContainer()->get(RecordMetricsExtractor::class)->extract($record);
        $this->em->flush();
    }
}
