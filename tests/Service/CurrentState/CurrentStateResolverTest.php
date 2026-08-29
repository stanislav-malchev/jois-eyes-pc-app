<?php

namespace App\Tests\Service\CurrentState;

use App\Backdoor\LiveVitalsResolver;
use App\Backdoor\SnapshotClient;
use App\Entity\NamedBluetoothDevice;
use App\Entity\NamedLocation;
use App\Entity\NamedNetwork;
use App\Repository\NamedBluetoothDeviceRepository;
use App\Repository\NamedLocationRepository;
use App\Repository\NamedNetworkRepository;
use App\Repository\RecordRepository;
use App\Service\Consolidation\DailyStepsConsolidator;
use App\Service\Consolidation\DataOriginPriority;
use App\Service\CurrentState\CurrentStateResolver;
use App\Service\Normalization\RecordMetricsExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `resolve()` always attempts a live phone read first (see the resolver's
 * own docblock) — so most cases here mock the HTTP client's live snapshot
 * response directly. There is no cache layer anymore (the-breath's
 * cadence poller was retired 29.08.2026) — the DB-only-fallback tests
 * deliberately make the live call fail (connection refused) to exercise
 * that branch directly.
 */
class CurrentStateResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordRepository $records;
    private NamedLocationRepository $namedLocations;
    private NamedBluetoothDeviceRepository $namedBluetoothDevices;
    private NamedNetworkRepository $namedNetworks;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->records = self::getContainer()->get(RecordRepository::class);
        $this->namedLocations = self::getContainer()->get(NamedLocationRepository::class);
        $this->namedBluetoothDevices = self::getContainer()->get(NamedBluetoothDeviceRepository::class);
        $this->namedNetworks = self::getContainer()->get(NamedNetworkRepository::class);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testLiveSnapshotIsReturnedWholeWithRawSectionsIntact(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'meta' => ['app_version' => '1.4.0', 'device' => 'SM-S911B'],
            'power' => ['level' => 82, 'charging' => true],
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs - 30_000, 'top_app' => 'com.termux'],
            'dnd' => ['mode' => 'off'],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'walking', 'steps_today' => 4231, 'steps_ts' => $nowMs - 30_000, 'confidence' => 92],
            'environment' => ['light_lux' => 3.2],
            'wearables' => ['band' => ['connected' => true, 'name' => 'Mi Band 7', 'hr_bpm' => 100, 'hr_ts' => $nowMs - 30_000]],
            'health_connect' => ['permission' => true, 'sources' => ['com.mi.health']],
            'connectivity' => ['network' => 'wifi', 'wifi_ssid' => null],
        ]);
        $this->insertHome();

        $data = $this->resolver($httpClient)->resolve();

        // Every raw section survives untouched.
        self::assertSame('1.4.0', $data['meta']['app_version']);
        self::assertSame(82, $data['power']['level']);
        self::assertSame('com.termux', $data['screen']['top_app']);
        self::assertSame('off', $data['dnd']['mode']);
        self::assertSame(3.2, $data['environment']['light_lux']);
        self::assertSame('Mi Band 7', $data['wearables']['band']['name']);
        self::assertSame(['com.mi.health'], $data['health_connect']['sources']);
        self::assertSame('wifi', $data['connectivity']['network']);

        // Decorations layered alongside, not instead of, the raw fields.
        self::assertSame('live', $data['verdict']);
        self::assertSame('live', $data['source']);
        self::assertSame('likely', $data['looking_at_phone']);
        self::assertSame('Home', $data['location']['place']);
        self::assertSame('geofence', $data['location']['source']);
        self::assertSame('light', $data['activity']['intensity']); // walking + HR 100 (90-110)
        self::assertTrue($data['activity']['in_motion']);
        self::assertNull($data['fallback']);
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testWifiMatchWinsOverGeofence(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        // Coordinates deliberately near "Office", but the SSID says Home.
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.203192, 'lon' => 27.908292, 'accuracy_m' => 8.0],
            'connectivity' => ['wifi_ssid' => 'HomeNet'],
            'activity' => ['type' => 'stationary', 'steps_today' => 100, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);
        $this->insertHome(wifiSsid: 'HomeNet');
        $this->insertNamedLocation('Office', 43.203192, 27.908292, 1000);

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('Home', $data['location']['place']);
        self::assertSame('wifi', $data['location']['source']);
        self::assertSame('no', $data['looking_at_phone']);
    }

    public function testStaleSnapshotOlderThan24HoursYieldsBlindNotStale(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $twoDaysMs = 2 * 24 * 60 * 60 * 1000;
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => $nowMs - $twoDaysMs],
            'location' => ['ts' => $nowMs - $twoDaysMs, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - $twoDaysMs],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => 60, 'hr_ts' => $nowMs - $twoDaysMs]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('blind', $data['verdict']);
    }

    public function testNeverInfersIntensityFromStaleHeartRate(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs - 30_000],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'walking', 'steps_today' => 500, 'steps_ts' => $nowMs - 30_000],
            // 40 minutes old — past HR's 30-min stale threshold.
            'wearables' => ['band' => ['connected' => true, 'hr_bpm' => 120, 'hr_ts' => $nowMs - 40 * 60_000]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('unknown', $data['activity']['intensity']);
    }

    public function testRunningIsHighIntensityRegardlessOfHeartRateFreshness(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs - 30_000],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'running', 'steps_today' => 500, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('high', $data['activity']['intensity']);
        self::assertTrue($data['activity']['in_motion']);
    }

    public function testAvgHrTodayIsWeightedAverageOfTodaysHeartRateRecords(): void
    {
        // Anchored to Europe/Sofia's midnight, not PHP's default-timezone
        // (UTC) "today" — averageHeartRateToday() computes its day
        // boundary in Sofia terms, and the two disagree for a few hours
        // every night (Sofia is UTC+2/+3, so its midnight is still
        // "yesterday" in UTC).
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Sofia'));
        $this->insertHeartRateRecord(60, $today->modify('+1 hour'));
        $this->insertHeartRateRecord(100, $today->modify('+2 hours'));

        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => true, 'hr_bpm' => 72, 'hr_ts' => $nowMs - 30_000]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame(72, $data['wearables']['band']['hr_bpm']); // the live reading, untouched
        self::assertSame(80, $data['wearables']['band']['avg_hr_today']); // (60+100)/2
    }

    public function testAvgHrTodayIsNullWithNoHeartRateRecordsTodayAndPresentOnDbOnlyFallbackToo(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);
        $data = $this->resolver($httpClient)->resolve();
        self::assertNull($data['wearables']['band']['avg_hr_today']);

        $this->insertHeartRateRecord(90, new \DateTimeImmutable('today +3 hours', new \DateTimeZone('Europe/Sofia')));
        $unreachable = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $dbOnlyData = $this->resolver($unreachable)->resolve();
        self::assertSame('db', $dbOnlyData['source']);
        self::assertSame(90, $dbOnlyData['wearables']['band']['avg_hr_today']);
    }

    public function testSleepIsPresentAndIdenticalOnBothLiveAndDbOnlyBranches(): void
    {
        $start = new \DateTimeImmutable('-9 hours');
        $end = new \DateTimeImmutable('-2 hours');
        $this->insertSleepRecord($start, $end, [
            ['startTime' => $start, 'endTime' => $start->modify('+3 hours'), 'stage' => 'light'],
            ['startTime' => $start->modify('+3 hours'), 'endTime' => $end, 'stage' => 'deep'],
        ]);

        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);
        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('live', $data['source']);
        self::assertSame(420, $data['sleep']['duration_minutes']); // 7 hours
        self::assertSame('7h 0m', $data['sleep']['duration_formatted']);
        self::assertSame(['light' => 180, 'deep' => 240], $data['sleep']['stages_minutes']);
        self::assertSame('2h 0m ago', $data['sleep']['ended_relative']);

        $unreachable = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $dbOnlyData = $this->resolver($unreachable)->resolve();

        self::assertSame('db', $dbOnlyData['source']);
        self::assertSame($data['sleep'], $dbOnlyData['sleep']);
    }

    public function testSleepFieldsAreAllNullWhenNoSleepRecordExists(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $data = $this->resolver($httpClient)->resolve();

        self::assertNull($data['sleep']['start_time']);
        self::assertNull($data['sleep']['end_time']);
        self::assertNull($data['sleep']['duration_minutes']);
        self::assertNull($data['sleep']['duration_formatted']);
        self::assertNull($data['sleep']['stages_minutes']);
        self::assertNull($data['sleep']['ended_relative']);
    }

    public function testUnreachablePhoneFallsBackToDbAndReportsLastAutoSync(): void
    {
        $this->insertHeartRateRecord(72, new \DateTimeImmutable('-10 minutes'));

        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $data = $this->resolver($httpClient)->resolve();

        self::assertContains($data['verdict'], ['stale', 'live', 'recent']);
        self::assertSame(72, $data['wearables']['band']['hr_bpm']);
        self::assertSame('db', $data['source']);
        self::assertNotNull($data['fallback']);
        self::assertSame('no live data', $data['fallback']['reason']);
    }

    public function testUnreachablePhoneAndEmptyDbYieldsBlindWithNullFallback(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('blind', $data['verdict']);
        self::assertSame('db', $data['source']);
        self::assertSame('unknown', $data['looking_at_phone']);
        self::assertNull($data['fallback']['last_auto_sync']);
    }

    public function testFetchesFreshSensorDataViaGpsFixAndBtScanQueryParams(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $snapshot = json_encode([
            'api_version' => 1,
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);
        $requestedQuery = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($snapshot, &$requestedQuery) {
            $requestedQuery = parse_url($url, \PHP_URL_QUERY);

            return new MockResponse($snapshot);
        });

        $this->resolver($httpClient)->resolve();

        self::assertNotNull($requestedQuery);
        parse_str($requestedQuery, $params);
        self::assertSame('fix', $params['gps'] ?? null);
        self::assertSame('scan', $params['bt'] ?? null);
    }

    public function testServerTimeIsAlwaysFirstAndInSofiaTimezone(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs - 30_000],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'walking', 'steps_today' => 4231, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => true, 'hr_bpm' => 100, 'hr_ts' => $nowMs - 30_000]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('server_time', array_key_first($data));
        self::assertSame('Europe/Sofia', $data['server_time']['timezone']);
        self::assertNotEmpty($data['server_time']['iso']);
        self::assertIsInt($data['server_time']['unix']);
    }

    public function testServerTimeIsFirstOnDbOnlyFallbackToo(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('server_time', array_key_first($data));
        self::assertSame('Europe/Sofia', $data['server_time']['timezone']);
    }

    public function testBtDevicesKeepRawFieldsAndGainLabelNoteWhenMatched(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $this->insertNamedBluetoothDevice('Nissan Leaf', macAddress: 'AA:BB:CC:DD:EE:FF', notes: 'Stan is driving the Nissan Leaf (or idling in a parking lot)');
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
            'bt_devices' => [
                ['name' => 'Nissan Leaf BT', 'address' => 'aa:bb:cc:dd:ee:ff', 'rssi_dbm' => -52, 'ts' => $nowMs],
                ['name' => 'Unknown-Device-7F', 'address' => '11:22:33:44:55:66', 'rssi_dbm' => -80, 'ts' => $nowMs],
            ],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertCount(2, $data['bt_devices']);
        // Raw fields (name, address, rssi_dbm, ts) always pass through unchanged.
        self::assertSame('Nissan Leaf BT', $data['bt_devices'][0]['name']);
        self::assertSame('aa:bb:cc:dd:ee:ff', $data['bt_devices'][0]['address']);
        self::assertSame(-52, $data['bt_devices'][0]['rssi_dbm']);
        // The match only adds label/note on top.
        self::assertSame('Nissan Leaf', $data['bt_devices'][0]['label']);
        self::assertSame('Stan is driving the Nissan Leaf (or idling in a parking lot)', $data['bt_devices'][0]['note']);

        self::assertSame('Unknown-Device-7F', $data['bt_devices'][1]['name']);
        self::assertSame(-80, $data['bt_devices'][1]['rssi_dbm']);
        self::assertNull($data['bt_devices'][1]['label']);
        self::assertNull($data['bt_devices'][1]['note']);
    }

    public function testBtDevicesIsEmptyArrayWhenSnapshotHasNoneOrOnDbOnlyFallback(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);
        $data = $this->resolver($httpClient)->resolve();
        self::assertSame([], $data['bt_devices']);

        $unreachable = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $dbOnlyData = $this->resolver($unreachable)->resolve();
        self::assertSame([], $dbOnlyData['bt_devices']);
    }

    public function testConnectivityGainsWifiLabelAndNoteWhenSsidMatchesNamedNetwork(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $this->insertNamedNetwork('Bedroom', 'BedroomNet', notes: 'The bedroom WiFi extender — Stan is probably in bed');
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
            'connectivity' => ['network' => 'wifi', 'wifi_ssid' => 'bedroomnet'],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        // Raw field untouched, matched case-insensitively.
        self::assertSame('bedroomnet', $data['connectivity']['wifi_ssid']);
        self::assertSame('Bedroom', $data['connectivity']['wifi_label']);
        self::assertSame('The bedroom WiFi extender — Stan is probably in bed', $data['connectivity']['wifi_note']);
    }

    public function testConnectivityWifiLabelAndNoteAreNullWhenNoSsidOrNoMatch(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $this->insertNamedNetwork('Bedroom', 'BedroomNet');
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
            'connectivity' => ['network' => 'mobile', 'wifi_ssid' => null],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertNull($data['connectivity']['wifi_ssid']);
        self::assertNull($data['connectivity']['wifi_label']);
        self::assertNull($data['connectivity']['wifi_note']);
    }

    public function testLocationNoteIsClosestKnownPlaceIgnoringGeofenceRadius(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        // Small radius on both — the live coordinate falls outside both
        // geofences (~20m past Ballet's, ~87m past Home's).
        $this->insertNamedLocation('Home', 43.225400, 28.000800, 10);
        $this->insertNamedLocation('Sofi\'s Ballet Class', 43.226000, 28.000800, 10);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.226180, 'lon' => 28.000800, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('unknown', $data['location']['place']);
        self::assertSame('location is closest to "Sofi\'s Ballet Class"', $data['location']['note']);
    }

    public function testScreenNoteIsPreciseSecondsAgoUnderAMinute(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => true, 'last_unlocked_ts' => $nowMs - 44_000],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertMatchesRegularExpression('/^4[4-6]s ago$/', $data['screen']['note']);
    }

    public function testScreenNoteCombinesHoursAndMinutesForOlderUnlocks(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        // 3h 14m 20s ago — the 20s buffer keeps this stable against test timing jitter.
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => $nowMs - ((3 * 3600 + 14 * 60 + 20) * 1000)],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertSame('3h 14m ago', $data['screen']['note']);
    }

    public function testScreenNoteIsNullWhenNeverUnlocked(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $httpClient = $this->httpClientForSnapshot([
            'screen' => ['on' => false, 'last_unlocked_ts' => null],
            'location' => ['ts' => $nowMs - 30_000, 'lat' => 43.225433, 'lon' => 28.000841, 'accuracy_m' => 8.0],
            'activity' => ['type' => 'stationary', 'steps_today' => 0, 'steps_ts' => $nowMs - 30_000],
            'wearables' => ['band' => ['connected' => false, 'hr_bpm' => null, 'hr_ts' => null]],
        ]);

        $data = $this->resolver($httpClient)->resolve();

        self::assertNull($data['screen']['note']);
    }

    private function resolver(?MockHttpClient $httpClient = null): CurrentStateResolver
    {
        $snapshotClient = new SnapshotClient($httpClient ?? new MockHttpClient(new MockResponse('', ['error' => 'not used'])), 'http://phone.test:8788');
        $stepsConsolidator = new DailyStepsConsolidator(new DataOriginPriority(), $this->records);
        $vitals = new LiveVitalsResolver($snapshotClient, $this->records, $stepsConsolidator);

        return new CurrentStateResolver(
            $snapshotClient,
            $vitals,
            $this->records,
            $this->namedLocations,
            $this->namedBluetoothDevices,
            $this->namedNetworks,
        );
    }

    /**
     * @param array<string, mixed> $snapshotBody
     */
    private function httpClientForSnapshot(array $snapshotBody): MockHttpClient
    {
        return new MockHttpClient(new MockResponse(json_encode($snapshotBody + ['api_version' => 1])));
    }

    private function insertNamedNetwork(string $name, string $ssid, ?string $notes = null): void
    {
        $network = (new NamedNetwork())
            ->setName($name)
            ->setSsid($ssid)
            ->setNotes($notes);
        $this->em->persist($network);
        $this->em->flush();
    }

    private function insertNamedBluetoothDevice(string $name, ?string $deviceName = null, ?string $macAddress = null, ?string $notes = null): void
    {
        $device = (new NamedBluetoothDevice())
            ->setName($name)
            ->setDeviceName($deviceName)
            ->setMacAddress($macAddress)
            ->setNotes($notes);
        $this->em->persist($device);
        $this->em->flush();
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
        $record = $this->records->upsertByRecordUid(
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
        // averageHeartRateToday() reads the denormalized metric-cache
        // columns (RecordMetricsExtractor), not the raw payload directly.
        (new RecordMetricsExtractor())->extract($record);
        $this->em->flush();
    }

    /**
     * @param array<int, array{startTime: \DateTimeImmutable, endTime: \DateTimeImmutable, stage: string}> $stages
     */
    private function insertSleepRecord(\DateTimeImmutable $start, \DateTimeImmutable $end, array $stages): void
    {
        $this->records->upsertByRecordUid(
            'sleep-'.$start->getTimestamp(),
            'health_connect',
            'SleepSessionRecord',
            $start,
            $end,
            $end,
            $end,
            false,
            [
                'startTime' => $start->format(\DateTimeInterface::ATOM),
                'endTime' => $end->format(\DateTimeInterface::ATOM),
                'stages' => array_map(static fn (array $s) => [
                    'startTime' => $s['startTime']->format(\DateTimeInterface::ATOM),
                    'endTime' => $s['endTime']->format(\DateTimeInterface::ATOM),
                    'stage' => $s['stage'],
                ], $stages),
                'dataOrigin' => 'com.android.healthconnect',
            ],
        );
        $this->em->flush();
    }
}
