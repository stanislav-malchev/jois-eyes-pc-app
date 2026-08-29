<?php

namespace App\Tests\MCP\Tools;

use App\Backdoor\LiveVitalsResolver;
use App\Backdoor\SnapshotClient;
use App\Entity\NamedLocation;
use App\MCP\Tools\CurrentStateTool;
use App\Repository\NamedLocationRepository;
use App\Repository\RecordRepository;
use App\Service\Consolidation\DailyStepsConsolidator;
use App\Service\Consolidation\DataOriginPriority;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CurrentStateToolTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordRepository $records;
    private NamedLocationRepository $namedLocations;
    private string $stateFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->records = self::getContainer()->get(RecordRepository::class);
        $this->namedLocations = self::getContainer()->get(NamedLocationRepository::class);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->stateFile = tempnam(sys_get_temp_dir(), 'current_state_');
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    public function testFreshCachedSnapshotYieldsLiveVerdictAndLikelyLookingAtPhone(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $this->writeCache([
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs - 30_000],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'wifi_ssid' => null,
            'activity' => ['type' => 'walking', 'steps_today' => 4231, 'steps_ts' => $nowMs - 30_000],
            'band' => ['connected' => true, 'hr_bpm' => 100, 'hr_ts' => $nowMs - 30_000],
        ]);
        $this->insertHome();

        $result = $this->tool()->execute([]);
        $data = $this->decode($result);

        self::assertSame('live', $data['verdict']);
        self::assertSame('likely', $data['looking_at_phone']);
        self::assertSame('Home', $data['location']['place']);
        self::assertSame('geofence', $data['location']['source']);
        self::assertSame('light', $data['activity']['intensity']); // walking + HR 100 (90-110)
        self::assertTrue($data['activity']['in_motion']);
        self::assertNull($data['fallback']);
    }

    public function testWifiMatchWinsOverGeofence(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        // Coordinates deliberately near "Office", but the SSID says Home.
        $this->writeCache([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.203192, 'lon' => 27.908292, 'accuracy_m' => 8.0],
            'wifi_ssid' => 'HomeNet',
            'activity' => ['type' => 'stationary', 'steps_today' => 100, 'steps_ts' => $nowMs - 30_000],
            'band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null],
        ]);
        $this->insertHome(wifiSsid: 'HomeNet');
        $this->insertNamedLocation('Office', 43.203192, 27.908292, 1000);

        $data = $this->decode($this->tool()->execute([]));

        self::assertSame('Home', $data['location']['place']);
        self::assertSame('wifi', $data['location']['source']);
        self::assertSame('no', $data['looking_at_phone']);
    }

    public function testStaleCacheOlderThan24HoursYieldsBlindNotStale(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $twoDaysMs = 2 * 24 * 60 * 60 * 1000;
        $this->writeCache([
            'screen' => ['on' => false, 'last_unlocked_ts' => $nowMs - $twoDaysMs],
            'location' => ['ts' => $nowMs - $twoDaysMs, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'wifi_ssid' => null,
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - $twoDaysMs],
            'band' => ['connected' => false, 'hr_bpm' => 60, 'hr_ts' => $nowMs - $twoDaysMs],
        ]);

        $data = $this->decode($this->tool()->execute([]));

        self::assertSame('blind', $data['verdict']);
    }

    public function testNeverInfersIntensityFromStaleHeartRate(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $this->writeCache([
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs - 30_000],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'wifi_ssid' => null,
            'activity' => ['type' => 'walking', 'steps_today' => 500, 'steps_ts' => $nowMs - 30_000],
            // 40 minutes old — past HR's 30-min stale threshold.
            'band' => ['connected' => true, 'hr_bpm' => 120, 'hr_ts' => $nowMs - 40 * 60_000],
        ]);

        $data = $this->decode($this->tool()->execute([]));

        self::assertSame('unknown', $data['activity']['intensity']);
    }

    public function testRunningIsHighIntensityRegardlessOfHeartRateFreshness(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $this->writeCache([
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs - 30_000],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'wifi_ssid' => null,
            'activity' => ['type' => 'running', 'steps_today' => 500, 'steps_ts' => $nowMs - 30_000],
            'band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null],
        ]);

        $data = $this->decode($this->tool()->execute([]));

        self::assertSame('high', $data['activity']['intensity']);
        self::assertTrue($data['activity']['in_motion']);
    }

    public function testForceHrFetchesLiveInsteadOfCache(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $this->writeCache([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'wifi_ssid' => null,
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'band' => ['connected' => false, 'hr_bpm' => 60, 'hr_ts' => $nowMs - 30_000],
        ]);

        $liveSnapshot = json_encode([
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs],
            'location' => ['ts' => $nowMs, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 5.0],
            'connectivity' => ['wifi_ssid' => null],
            'activity' => ['type' => 'stationary', 'steps_today' => 10, 'steps_ts' => $nowMs],
            'wearables' => ['band' => ['connected' => true, 'hr_bpm' => 200, 'hr_ts' => $nowMs]],
        ]);
        $httpClient = new MockHttpClient(new MockResponse($liveSnapshot));

        $data = $this->decode($this->tool($httpClient)->execute(['force_hr' => true]));

        self::assertSame(200, $data['band']['hr_bpm']);
        self::assertSame('live', $data['verdict']);
    }

    public function testNoCacheFallsBackToDbAndReportsLastAutoSync(): void
    {
        $this->insertHeartRateRecord(72, new \DateTimeImmutable('-10 minutes'));

        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        // No cache file written at all (fresh install scenario).
        $data = $this->decode($this->tool($httpClient, withStateFile: false)->execute([]));

        self::assertContains($data['verdict'], ['stale', 'live', 'recent']);
        self::assertSame(72, $data['band']['hr_bpm']);
        self::assertNotNull($data['fallback']);
        self::assertSame('no live data', $data['fallback']['reason']);
    }

    public function testNoCacheAndEmptyDbYieldsBlindWithNullFallback(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $data = $this->decode($this->tool($httpClient, withStateFile: false)->execute([]));

        self::assertSame('blind', $data['verdict']);
        self::assertSame('unknown', $data['looking_at_phone']);
        self::assertNull($data['fallback']['last_auto_sync']);
    }

    private function tool(?MockHttpClient $httpClient = null, bool $withStateFile = true): CurrentStateTool
    {
        $snapshotClient = new SnapshotClient($httpClient ?? new MockHttpClient(new MockResponse('', ['error' => 'not used'])), 'http://phone.test:8788');
        $stepsConsolidator = new DailyStepsConsolidator(new DataOriginPriority(), $this->records);
        $vitals = new LiveVitalsResolver($snapshotClient, $this->records, $stepsConsolidator);

        return new CurrentStateTool(
            $snapshotClient,
            $vitals,
            $this->records,
            $this->namedLocations,
            $withStateFile ? $this->stateFile : $this->stateFile.'-missing',
        );
    }

    /**
     * @param array<string, mixed> $lastSnapshot
     */
    private function writeCache(array $lastSnapshot): void
    {
        file_put_contents($this->stateFile, json_encode([
            'polled_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'phone_reachable' => true,
            'health_connect_age_seconds' => 60,
            'health_connect_dead' => false,
            'last_action' => 'none',
            'last_snapshot' => $lastSnapshot,
        ]));
    }

    private function insertHome(?string $wifiSsid = null): void
    {
        $this->insertNamedLocation('Home', 43.225433, 28.000841, 1000, $wifiSsid);
    }

    private function insertNamedLocation(string $name, float $lat, float $lon, int $radius, ?string $wifiSsid = null): void
    {
        $location = (new NamedLocation())
            ->setName($name)
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setRadiusMeters($radius)
            ->setWifiSsid($wifiSsid);
        $this->em->persist($location);
        $this->em->flush();
    }

    private function insertHeartRateRecord(int $bpm, \DateTimeImmutable $time): void
    {
        $this->records->upsertByRecordUid(
            'hr-'.$time->getTimestamp(),
            'health_connect',
            'HeartRate',
            $time,
            $time,
            $time,
            $time,
            false,
            ['samples' => [['time' => $time->format(\DateTimeInterface::ATOM), 'beatsPerMinute' => $bpm]], 'dataOrigin' => 'com.android.healthconnect'],
        );
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult $result): array
    {
        return $result->getStructuredValue();
    }
}
