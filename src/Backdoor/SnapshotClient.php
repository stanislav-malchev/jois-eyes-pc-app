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
 * per web request).
 *
 * There is no PC-side cadence poller anymore (the-breath's Symfony
 * Scheduler/Messenger worker was retired 29.08.2026 — see the LLM wiki's
 * entities/the-breath.md). Archival sync now runs solely on the phone's
 * own 15-min WorkManager floor, un-nudged; on-demand freshness is this
 * client's whole job, via App\Service\CurrentState\CurrentStateResolver.
 */
class SnapshotClient
{
    // The contract's on-demand timeout (§2).
    private const TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * No query params, so the phone answers with its default fast=1
     * behaviour — last-known values, no fresh GPS/BT radio work. Used by
     * App\Backdoor\LiveVitalsResolver's own opportunistic live-then-DB
     * checks (the narrow MCP tools) — cheaper than fetchFresh() when a
     * caller doesn't specifically need a forced sensor read.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(): ?array
    {
        return $this->get('/v1/snapshot');
    }

    /**
     * `gps=fix` + `bt=scan` — forces a fresh one-shot location fix (≤ ~3s)
     * and BT scan (≤ ~2s, currently a no-op phone-side but harmless/
     * future-proof to request) instead of the phone's last-known values.
     * Used by App\Service\CurrentState\CurrentStateResolver's on-demand
     * live read (a human or agent asking "what's the state right now").
     * There's no equivalent on-demand param for `activity.steps_today`/
     * `wearables.band.hr_bpm`; those are always whatever the phone app
     * already has in memory.
     *
     * @return array<string, mixed>|null
     */
    public function fetchFresh(): ?array
    {
        return $this->get('/v1/snapshot', ['gps' => 'fix', 'bt' => 'scan']);
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
