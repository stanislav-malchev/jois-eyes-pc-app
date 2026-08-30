<?php

namespace App\Service\PcState;

use Symfony\Component\Process\Process;

/**
 * Resolves a tailnet node's current IPv4 address by shelling out to
 * `tailscale status --json`, fresh on every call. Unlike the phone's
 * PHONE_BACKDOOR_URL (a stable env var — see App\Backdoor\SnapshotClient),
 * the WindowsEyes agent's node has no address we can just hardcode: the
 * LLM wiki's concepts/pc-presence-agent.md flags the IP as something that
 * can change, so this re-resolves on every App\Service\PcState\PcStateClient
 * call instead of caching it.
 *
 * Best-effort like SnapshotClient: any failure (tailscale binary missing,
 * tailscaled not running, host not present in the tailnet, malformed JSON)
 * returns null rather than throwing, so PcStateClient/PcStateResolver can
 * report an honest "unreachable" instead of crashing.
 */
class TailscaleHostResolver
{
    private const TIMEOUT_SECONDS = 5.0;

    public function resolveIp(string $hostname): ?string
    {
        $json = $this->fetchStatusJson();
        if (null === $json) {
            return null;
        }

        try {
            $status = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $nodes = array_merge([$status['Self'] ?? []], array_values($status['Peer'] ?? []));
        foreach ($nodes as $node) {
            // Match on the tailnet device name (DNSName's first label — the
            // name `tailscale status`'s plain-text column shows, e.g.
            // "desktop-msi"), NOT `HostName`. `HostName` is the underlying
            // OS-level machine name, which is not unique on this tailnet: a
            // WSL2 guest inherits its Windows host's computer name, so this
            // very box's own Self node reports `HostName: "DESKTOP-MSI"` —
            // identical to the real Windows-native "desktop-msi" peer this
            // resolver is looking for. Matching on `HostName` silently
            // resolved to this box's own IP instead (found live 30.08.2026).
            $dnsLabel = explode('.', $node['DNSName'] ?? '', 2)[0];
            if (0 !== strcasecmp($dnsLabel, $hostname)) {
                continue;
            }
            foreach ($node['TailscaleIPs'] ?? [] as $ip) {
                if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Split out from resolveIp() so tests can stub the process boundary
     * (override this method) without needing `tailscale` installed or a
     * real tailnet — the parsing/matching logic above is what's actually
     * worth exercising with canned JSON.
     */
    protected function fetchStatusJson(): ?string
    {
        try {
            $process = new Process(['tailscale', 'status', '--json']);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
