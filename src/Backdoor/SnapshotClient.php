<?php

namespace App\Backdoor;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the phone's on-demand `/v1/snapshot` endpoint (the "backdoor" —
 * see the LLM wiki's raw/joiseyes/joiseyes-backdoor-v1.md for the frozen
 * contract). Read-only, best-effort: any failure (phone asleep, tailnet
 * down, rate-limited, malformed JSON) returns null so callers fall back to
 * stored records instead of surfacing an error.
 *
 * Always makes a live call — deliberately not memoized. A single MCP tool
 * call only ever needs one read anyway (Symfony's own container is fresh
 * per web request), and BreathSchedule's poller is a long-running Messenger
 * worker that reuses this same instance across every tick, where caching
 * the first result forever would silently stop it from ever polling again.
 */
class SnapshotClient
{
    // The contract's on-demand timeout (§2) — longer than the 5s cadence
    // poll allowance, since this fires once per call, not in a tight loop.
    private const TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * No query params, so the phone answers with its default fast=1
     * behaviour — last-known values, no fresh GPS/BT radio work. Still far
     * fresher than our own sync cadence, without gps=fix/bt=scan's cost.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(): ?array
    {
        return $this->get('/v1/snapshot');
    }

    /**
     * Triggers the phone's Health Connect ingest+push chain (contract
     * §3.1). Acks immediately — {triggered, already_running, last_sync_ts}
     * — the phone never blocks the request on the chain itself, and
     * neither do we.
     *
     * @return array{triggered?: bool, already_running?: bool, last_sync_ts?: int}|null
     */
    public function triggerHealthConnectPull(): ?array
    {
        return $this->get('/v1/snapshot', ['hc' => 'pull']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').$path, [
                'query' => $query,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            return $response->toArray();
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
