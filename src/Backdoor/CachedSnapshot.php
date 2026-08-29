<?php

namespace App\Backdoor;

/**
 * Trims a raw /v1/snapshot document down to the fields
 * App\MCP\Tools\CurrentStateTool needs, in one canonical shape shared by
 * both producers: App\Scheduler\PollBackdoorMessageHandler (the 3-min
 * cadence poll, written to var/breath_state.json's `last_snapshot`) and
 * CurrentStateTool's own on-demand `force_steps`/`force_hr` live fetch. One
 * mapping, so the cached-vs-live paths can never silently disagree about
 * which raw fields matter.
 */
final class CachedSnapshot
{
    /**
     * @param array<string, mixed> $snapshot the raw decoded /v1/snapshot body
     * @return array<string, mixed>
     */
    public static function fromRaw(array $snapshot, \DateTimeImmutable $cachedAt): array
    {
        return [
            'cached_at' => $cachedAt->format(\DateTimeInterface::ATOM),
            'screen' => [
                'on' => $snapshot['screen']['on'] ?? null,
                'last_unlocked_ts' => $snapshot['screen']['last_unlocked_ts'] ?? null,
            ],
            'location' => [
                'ts' => $snapshot['location']['ts'] ?? null,
                'lat' => $snapshot['location']['lat'] ?? null,
                'lon' => $snapshot['location']['lon'] ?? null,
                'accuracy_m' => $snapshot['location']['accuracy_m'] ?? null,
            ],
            'wifi_ssid' => $snapshot['connectivity']['wifi_ssid'] ?? null,
            'activity' => [
                'type' => $snapshot['activity']['type'] ?? null,
                'steps_today' => $snapshot['activity']['steps_today'] ?? null,
                'steps_ts' => $snapshot['activity']['steps_ts'] ?? null,
            ],
            'band' => [
                'connected' => $snapshot['wearables']['band']['connected'] ?? null,
                'hr_bpm' => $snapshot['wearables']['band']['hr_bpm'] ?? null,
                'hr_ts' => $snapshot['wearables']['band']['hr_ts'] ?? null,
            ],
        ];
    }
}
