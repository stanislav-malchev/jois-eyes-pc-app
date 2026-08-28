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
