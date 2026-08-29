<?php

namespace App\Scheduler;

use App\Backdoor\CachedSnapshot;
use App\Backdoor\SnapshotClient;
use App\Scheduler\Message\PollBackdoorMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs every ~3 minutes (see BreathSchedule). Does the one thing the
 * on-demand MCP reads (App\Backdoor\LiveVitalsResolver) deliberately don't:
 * proactively trigger hc=pull once Health Connect data starts going stale,
 * per the contract's daemon guidance (LLM wiki concepts/hc-pull.md) —
 * "send hc=pull only when last_sync_ts is older than the freshness
 * threshold, not on every poll." Writes a small state file so the current
 * lane health is visible without re-polling (concepts/freshness.md's
 * thresholds; entities/the-breath.md calls this "the state file").
 *
 * Also caches the fields App\MCP\Tools\CurrentStateTool needs under
 * `last_snapshot` — its "cached breath" primary data source (LLM wiki
 * concepts/current-state-tool.md), so that tool answers from this file
 * instead of triggering its own radio wake on every call. A failed poll
 * (`$snapshot` null) leaves the previous `last_snapshot` in the file
 * untouched rather than blanking it — an old-but-present cached snapshot is
 * exactly what CurrentStateTool's own freshness/staleness math is for; a
 * null would look like "no data ever" instead of "old data".
 *
 * Deliberately does NOT do the-breath's other jobs (activity inference,
 * nudges) — those need real specification first; see entities/the-breath.md.
 */
#[AsMessageHandler]
class PollBackdoorMessageHandler
{
    // concepts/freshness.md: health_connect.last_sync_ts fresh < 15 min, dead > 60 min.
    private const HC_FRESH_SECONDS = 15 * 60;
    private const HC_DEAD_SECONDS = 60 * 60;

    public function __construct(
        private readonly SnapshotClient $snapshot,
        private readonly string $stateFile,
        private readonly string $logFile,
    ) {
    }

    public function __invoke(PollBackdoorMessage $message): void
    {
        $polledAt = new \DateTimeImmutable();
        $snapshot = $this->snapshot->fetch();

        if (!$snapshot) {
            $this->writeState($polledAt, reachable: false, hcAgeSeconds: null, action: 'none', snapshot: null);
            $this->log($polledAt, 'unreachable');

            return;
        }

        $lastSyncMs = $snapshot['health_connect']['last_sync_ts'] ?? null;
        $ageSeconds = null !== $lastSyncMs
            ? $polledAt->getTimestamp() - intdiv($lastSyncMs, 1000)
            : null;

        $action = 'none';
        if (null === $ageSeconds || $ageSeconds > self::HC_FRESH_SECONDS) {
            $ack = $this->snapshot->triggerHealthConnectPull();
            $action = ($ack['already_running'] ?? false) ? 'hc_pull_already_running' : 'hc_pull_triggered';

            if (null !== $ageSeconds && $ageSeconds > self::HC_DEAD_SECONDS) {
                $this->log($polledAt, sprintf(
                    'ALERT: health_connect lane dead — last_sync_ts %ds old despite hc=pull',
                    $ageSeconds,
                ));
            }
        }

        $this->writeState($polledAt, reachable: true, hcAgeSeconds: $ageSeconds, action: $action, snapshot: $snapshot);
        $this->log($polledAt, sprintf('ok hc_age=%s action=%s', $ageSeconds ?? 'null', $action));
    }

    /**
     * @param array<string, mixed>|null $snapshot the raw fetched snapshot, or null on an unreachable poll
     */
    private function writeState(\DateTimeImmutable $polledAt, bool $reachable, ?int $hcAgeSeconds, string $action, ?array $snapshot): void
    {
        $previous = $this->readExistingState();

        file_put_contents($this->stateFile, json_encode([
            'polled_at' => $polledAt->format(\DateTimeInterface::ATOM),
            'phone_reachable' => $reachable,
            'health_connect_age_seconds' => $hcAgeSeconds,
            'health_connect_dead' => null !== $hcAgeSeconds && $hcAgeSeconds > self::HC_DEAD_SECONDS,
            'last_action' => $action,
            'last_snapshot' => null !== $snapshot ? CachedSnapshot::fromRaw($snapshot, $polledAt) : ($previous['last_snapshot'] ?? null),
        ], \JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>
     */
    private function readExistingState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        return json_decode(file_get_contents($this->stateFile), true) ?? [];
    }

    private function log(\DateTimeImmutable $polledAt, string $message): void
    {
        file_put_contents($this->logFile, sprintf("[%s] %s\n", $polledAt->format('c'), $message), \FILE_APPEND);
    }
}
