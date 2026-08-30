<?php

namespace App\Service\PcState;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the WindowsEyes agent's `GET /idle` (see the LLM wiki's
 * concepts/pc-presence-agent.md) — milestone 1 of the PC presence lane,
 * proxied here as the "question" tier between the phone's cheap `/idle`
 * glance (not this service's concern — that's a direct client-side call to
 * the agent) and current-state's expensive full phone+band+sleep read.
 *
 * The agent binds loopback only today (see the wiki page's "v0 deviation"
 * note), so it isn't reachable from this box yet either — every call here
 * is expected to fail until the agent's tailnet bind lands. Best-effort
 * like App\Backdoor\SnapshotClient: any failure (host not resolvable,
 * agent not running, tailnet down, malformed JSON) returns null so
 * PcStateResolver reports an honest "unreachable" rather than crashing.
 */
class PcStateClient
{
    // Assigned 30.08.2026 as the sibling of the phone backdoor's 8788 —
    // see the LLM wiki's concepts/services-map.md.
    private const HOSTNAME = 'desktop-msi';
    private const PORT = 8789;

    // Tier-1 "question" call, meant to stay cheap — short timeout so a
    // dead/unreachable agent doesn't stall whoever's asking.
    private const TIMEOUT_SECONDS = 3.0;

    public function __construct(
        private readonly TailscaleHostResolver $tailscale,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchIdle(): ?array
    {
        $ip = $this->tailscale->resolveIp(self::HOSTNAME);
        if (null === $ip) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('http://%s:%d/idle', $ip, self::PORT), [
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            return $response->toArray();
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
