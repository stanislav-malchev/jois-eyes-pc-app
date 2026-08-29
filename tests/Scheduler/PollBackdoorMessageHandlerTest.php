<?php

namespace App\Tests\Scheduler;

use App\Backdoor\SnapshotClient;
use App\Scheduler\Message\PollBackdoorMessage;
use App\Scheduler\PollBackdoorMessageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PollBackdoorMessageHandlerTest extends TestCase
{
    private string $stateFile;
    private string $logFile;

    protected function setUp(): void
    {
        $this->stateFile = tempnam(sys_get_temp_dir(), 'breath_state_');
        $this->logFile = tempnam(sys_get_temp_dir(), 'breath_log_');
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        @unlink($this->logFile);
    }

    public function testUnreachablePhoneWritesUnreachableStateAndNeverTriggersPull(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $handler = $this->handler($httpClient);

        ($handler)(new PollBackdoorMessage());

        $state = $this->readState();
        self::assertFalse($state['phone_reachable']);
        self::assertSame('none', $state['last_action']);
        self::assertStringContainsString('unreachable', file_get_contents($this->logFile));
    }

    public function testFreshHealthConnectDataTriggersNoPull(): void
    {
        $freshTs = (time() - 60) * 1000; // 1 minute old
        $httpClient = $this->httpClientForSnapshot(['health_connect' => ['last_sync_ts' => $freshTs]]);
        $handler = $this->handler($httpClient);

        ($handler)(new PollBackdoorMessage());

        $state = $this->readState();
        self::assertTrue($state['phone_reachable']);
        self::assertSame('none', $state['last_action']);
        self::assertFalse($state['health_connect_dead']);
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testStaleHealthConnectDataTriggersPullWithoutAlert(): void
    {
        $staleTs = (time() - 20 * 60) * 1000; // 20 minutes old — stale, not dead
        $httpClient = $this->httpClientForSnapshot(
            ['health_connect' => ['last_sync_ts' => $staleTs]],
            pullAck: ['triggered' => true, 'already_running' => false],
        );
        $handler = $this->handler($httpClient);

        ($handler)(new PollBackdoorMessage());

        $state = $this->readState();
        self::assertSame('hc_pull_triggered', $state['last_action']);
        self::assertFalse($state['health_connect_dead']);
        self::assertStringNotContainsString('ALERT', file_get_contents($this->logFile));
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testDeadHealthConnectLaneTriggersPullAndLogsAlert(): void
    {
        $deadTs = (time() - 90 * 60) * 1000; // 90 minutes old — past the dead threshold
        $httpClient = $this->httpClientForSnapshot(
            ['health_connect' => ['last_sync_ts' => $deadTs]],
            pullAck: ['triggered' => false, 'already_running' => true],
        );
        $handler = $this->handler($httpClient);

        ($handler)(new PollBackdoorMessage());

        $state = $this->readState();
        self::assertSame('hc_pull_already_running', $state['last_action']);
        self::assertTrue($state['health_connect_dead']);
        self::assertStringContainsString('ALERT', file_get_contents($this->logFile));
    }

    public function testMissingLastSyncTimestampIsTreatedAsNeedingAPull(): void
    {
        $httpClient = $this->httpClientForSnapshot(
            ['health_connect' => ['last_sync_ts' => null]],
            pullAck: ['triggered' => true, 'already_running' => false],
        );
        $handler = $this->handler($httpClient);

        ($handler)(new PollBackdoorMessage());

        $state = $this->readState();
        self::assertNull($state['health_connect_age_seconds']);
        self::assertSame('hc_pull_triggered', $state['last_action']);
        self::assertFalse($state['health_connect_dead']);
    }

    public function testSuccessfulPollCachesSnapshotFieldsForCurrentStateTool(): void
    {
        $tsMs = (time() - 60) * 1000;
        $httpClient = $this->httpClientForSnapshot([
            'health_connect' => ['last_sync_ts' => $tsMs],
            'screen' => ['on' => true, 'last_unlocked_ts' => $tsMs],
            'location' => ['ts' => $tsMs, 'lat' => 43.22, 'lon' => 28.0, 'accuracy_m' => 8.4],
            'connectivity' => ['wifi_ssid' => 'HomeNet'],
            'activity' => ['type' => 'walking', 'steps_today' => 4231, 'steps_ts' => $tsMs],
            'wearables' => ['band' => ['connected' => true, 'hr_bpm' => 78, 'hr_ts' => $tsMs]],
        ]);
        $handler = $this->handler($httpClient);

        ($handler)(new PollBackdoorMessage());

        $snapshot = $this->readState()['last_snapshot'];
        self::assertTrue($snapshot['screen']['on']);
        self::assertSame(43.22, $snapshot['location']['lat']);
        self::assertSame('HomeNet', $snapshot['wifi_ssid']);
        self::assertSame('walking', $snapshot['activity']['type']);
        self::assertSame(4231, $snapshot['activity']['steps_today']);
        self::assertSame(78, $snapshot['band']['hr_bpm']);
    }

    public function testUnreachablePollPreservesThePreviousCachedSnapshot(): void
    {
        $tsMs = (time() - 60) * 1000;
        $reachableClient = $this->httpClientForSnapshot([
            'health_connect' => ['last_sync_ts' => $tsMs],
            'wearables' => ['band' => ['connected' => true, 'hr_bpm' => 78, 'hr_ts' => $tsMs]],
        ]);
        ($this->handler($reachableClient))(new PollBackdoorMessage());
        $cachedAt = $this->readState()['last_snapshot']['cached_at'];

        $unreachableClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        ($this->handler($unreachableClient))(new PollBackdoorMessage());

        $state = $this->readState();
        self::assertFalse($state['phone_reachable']);
        self::assertSame(78, $state['last_snapshot']['band']['hr_bpm']);
        self::assertSame($cachedAt, $state['last_snapshot']['cached_at']);
    }

    private function handler(MockHttpClient $httpClient): PollBackdoorMessageHandler
    {
        return new PollBackdoorMessageHandler(
            new SnapshotClient($httpClient, 'http://phone.test:8788'),
            $this->stateFile,
            $this->logFile,
        );
    }

    private function httpClientForSnapshot(array $snapshotBody, ?array $pullAck = null): MockHttpClient
    {
        $snapshot = json_encode($snapshotBody + ['api_version' => 1]);
        $ack = json_encode(($pullAck ?? []) + ['triggered' => false, 'already_running' => false, 'last_sync_ts' => null]);

        return new MockHttpClient(function (string $method, string $url) use ($snapshot, $ack) {
            return new MockResponse(str_contains($url, 'hc=pull') ? $ack : $snapshot);
        });
    }

    private function readState(): array
    {
        return json_decode(file_get_contents($this->stateFile), true);
    }
}
