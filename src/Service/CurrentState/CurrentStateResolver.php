<?php

namespace App\Service\CurrentState;

use App\Backdoor\CachedSnapshot;
use App\Backdoor\LiveVitalsResolver;
use App\Backdoor\SnapshotClient;
use App\Entity\NamedBluetoothDevice;
use App\Entity\NamedLocation;
use App\Repository\NamedBluetoothDeviceRepository;
use App\Repository\NamedLocationRepository;
use App\Repository\NamedNetworkRepository;
use App\Repository\RecordRepository;
use Location\Coordinate;
use Location\Distance\Haversine;

/**
 * "The Watcher" — one call answering Stan's whole presence state, per the
 * LLM wiki's features/current-state-tool.md. Both App\MCP\Tools\
 * CurrentStateTool (for an agent) and App\Controller\CurrentStateController's
 * `GET /api/v1/current-state` (for Stan himself — curl/.http/browser, no
 * MCP client needed) are thin callers of this one `resolve()`, so they
 * can never drift apart.
 *
 * Rebuilt 29.08.2026 per Stan: the previous version reshaped the phone's
 * snapshot into a hand-picked subset of fields — "a chopped up version".
 * Every call now returns the phone's **entire** raw `/v1/snapshot` document
 * (every section: meta, power, screen, dnd, location, activity,
 * environment, wearables, health_connect, connectivity, bt_devices) with
 * our own decorations layered *into* it — new keys added alongside the
 * raw ones (`location.note`, `screen.note`, `bt_devices[].label`,
 * `connectivity.wifi_label`, etc.), nothing ever removed, renamed, or
 * reshaped.
 *
 * Data source priority:
 *   1. Live — always attempted first, unconditionally (a real
 *      SnapshotClient::fetch() call to the phone, every single call; no
 *      more cache-by-default/force-flag distinction — simpler, and
 *      guarantees this always reflects the exact same data a manual
 *      /v1/snapshot poll would. Known trade-off, deliberate for now: this
 *      wakes the phone's radio on every call, unlike the previous
 *      cached-breath-by-default design.
 *   2. Cached breath — only when the live call fails (phone asleep/tailnet
 *      down/rate-limited): the last full raw snapshot
 *      App\Scheduler\PollBackdoorMessageHandler cached in
 *      var/breath_state.json, via the (now pass-through, not trimming)
 *      App\Backdoor\CachedSnapshot mapper.
 *   3. joiseyes DB — only when there is no cache at all (fresh install, or
 *      the daemon's been down since before this process started). Falls
 *      back to App\Backdoor\LiveVitalsResolver for location/heart
 *      rate/steps (itself live-then-DB) and RecordRepository::
 *      latestReceivedAt() for `fallback.last_auto_sync`. Necessarily a
 *      reduced, synthetic shape here — there is no real snapshot document
 *      to reflect when this branch is reached.
 *
 * Known v1 simplification, documented rather than silently skipped:
 * `in_motion`/`intensity`'s "high step delta" escape hatch needs a
 * steps_since_boot delta tracked across polls, which isn't persisted
 * anywhere yet — intensity/in_motion here are derived from activity.type +
 * fresh HR only.
 */
class CurrentStateResolver
{
    private const TIMEZONE = 'Europe/Sofia';
    private const BLIND_SECONDS = 24 * 60 * 60;

    // concepts/freshness.md thresholds, in seconds.
    private const SCREEN_FRESH = 5 * 60;
    private const SCREEN_STALE = 15 * 60;
    private const LOCATION_FRESH = 2 * 60;
    private const LOCATION_STALE = 10 * 60;
    private const STEPS_FRESH = 5 * 60;
    private const STEPS_STALE = 30 * 60;
    private const HR_FRESH = 10 * 60;
    private const HR_STALE = 30 * 60;

    public function __construct(
        private readonly SnapshotClient $snapshotClient,
        private readonly LiveVitalsResolver $vitals,
        private readonly RecordRepository $records,
        private readonly NamedLocationRepository $namedLocations,
        private readonly NamedBluetoothDeviceRepository $namedBluetoothDevices,
        private readonly NamedNetworkRepository $namedNetworks,
        private readonly string $stateFile,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $now = new \DateTimeImmutable();

        $raw = $this->snapshotClient->fetch();
        $raw ??= $this->readCachedSnapshot();

        if (null === $raw) {
            return $this->buildFromDbOnly($now);
        }

        return $this->decorate($raw, $now);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCachedSnapshot(): ?array
    {
        if (!is_file($this->stateFile)) {
            return null;
        }

        $state = json_decode(file_get_contents($this->stateFile), true);

        return $state['last_snapshot'] ?? null;
    }

    /**
     * Merges verdict/notes/ages into the raw snapshot untouched — every
     * raw key from `$raw` survives; only new keys are added, either at the
     * top level or as new siblings inside `screen`/`location`/`activity`/
     * `wearables.band`/each `bt_devices[]` entry.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function decorate(array $raw, \DateTimeImmutable $now): array
    {
        $screenOn = $raw['screen']['on'] ?? null;
        $unlockedAge = $this->ageSeconds($raw['screen']['last_unlocked_ts'] ?? null, $now);
        $locationAge = $this->ageSeconds($raw['location']['ts'] ?? null, $now);
        $stepsAge = $this->ageSeconds($raw['activity']['steps_ts'] ?? null, $now);
        $hrAge = $this->ageSeconds($raw['wearables']['band']['hr_ts'] ?? null, $now);

        $hrStatus = $this->statusOf($hrAge, self::HR_FRESH, self::HR_STALE);

        $presentStatuses = array_values(array_filter([
            $this->statusOf($unlockedAge, self::SCREEN_FRESH, self::SCREEN_STALE),
            $this->statusOf($locationAge, self::LOCATION_FRESH, self::LOCATION_STALE),
            $this->statusOf($stepsAge, self::STEPS_FRESH, self::STEPS_STALE),
            $hrStatus,
        ], static fn (?string $s) => null !== $s));
        $presentAges = array_values(array_filter(
            [$unlockedAge, $locationAge, $stepsAge, $hrAge],
            static fn (?int $a) => null !== $a,
        ));

        $verdict = $this->overallVerdict($presentStatuses, $presentAges);

        $lookingAtPhone = match (true) {
            null === $screenOn => 'unknown',
            false === $screenOn => 'no',
            null !== $unlockedAge && $unlockedAge < self::SCREEN_FRESH => 'likely',
            default => 'unknown',
        };

        $wifiSsid = $raw['connectivity']['wifi_ssid'] ?? null;
        $place = $this->resolvePlace($wifiSsid, $raw['location']['lat'] ?? null, $raw['location']['lon'] ?? null);
        $network = $this->resolveNetwork($wifiSsid);

        $activityType = $raw['activity']['type'] ?? null;
        $hrBpm = $raw['wearables']['band']['hr_bpm'] ?? null;

        $decorated = $raw;
        $decorated['screen']['note'] = null !== $unlockedAge ? $this->relativeTime($unlockedAge) : null;
        $decorated['location']['place'] = $place['place'];
        $decorated['location']['source'] = $place['source'];
        $decorated['location']['note'] = $place['note'];
        $decorated['location']['age_relative'] = null !== $locationAge ? $this->relativeTime($locationAge) : null;
        $decorated['activity']['steps_age_relative'] = null !== $stepsAge ? $this->relativeTime($stepsAge) : null;
        $decorated['activity']['intensity'] = $this->intensityOf($activityType, $hrBpm, $hrStatus);
        $decorated['activity']['in_motion'] = $this->inMotionOf($activityType);
        $decorated['wearables']['band']['hr_age_relative'] = null !== $hrAge ? $this->relativeTime($hrAge) : null;
        $decorated['connectivity']['wifi_label'] = $network['label'];
        $decorated['connectivity']['wifi_note'] = $network['note'];
        $decorated['bt_devices'] = $this->resolveBtDevices($raw['bt_devices'] ?? []);

        return [
            'server_time' => $this->serverTimeBlock($now),
            'verdict' => $verdict,
            'looking_at_phone' => $lookingAtPhone,
            'last_activity' => [] !== $presentAges ? $this->relativeBlock(min($presentAges)) : $this->nullRelativeBlock(),
        ] + $decorated + ['fallback' => null];
    }

    /**
     * Branch 3: no live read, and the-breath has never successfully cached
     * a snapshot at all (fresh install, or the daemon's been down since
     * before this process started). Necessarily a reduced, synthetic
     * shape — there is no raw document to reflect here. Falls back to
     * LiveVitalsResolver (itself live-then-DB) for location/heart
     * rate/steps, and to RecordRepository::latestReceivedAt() for
     * `fallback.last_auto_sync`. Everything a real snapshot would carry
     * that has no DB equivalent (power/dnd/environment/health_connect/
     * connectivity/bt_devices/screen/activity.type) is just absent —
     * honestly, not guessed.
     *
     * @return array<string, mixed>
     */
    private function buildFromDbOnly(\DateTimeImmutable $now): array
    {
        $location = $this->vitals->getLocation();
        $heartRate = $this->vitals->getHeartRate();
        $stepsToday = $this->vitals->getStepsToday(
            (new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE)))->setTimezone(new \DateTimeZone('UTC')),
        );

        $locationAge = $location ? $now->getTimestamp() - $location['timestamp']->getTimestamp() : null;
        $hrAge = $heartRate ? $now->getTimestamp() - $heartRate['timestamp']->getTimestamp() : null;
        $presentAges = array_values(array_filter([$locationAge, $hrAge], static fn (?int $a) => null !== $a));

        $latestReceivedAt = $this->records->latestReceivedAt();
        $syncAge = $latestReceivedAt ? $now->getTimestamp() - $latestReceivedAt->getTimestamp() : null;

        $bestAge = [] !== $presentAges ? min($presentAges) : $syncAge;
        $verdict = null === $bestAge ? 'blind' : ($bestAge > self::BLIND_SECONDS ? 'blind' : 'stale');

        $place = $location ? $this->resolvePlace(null, $location['latitude'], $location['longitude']) : ['place' => 'unknown', 'source' => null, 'note' => null];

        return [
            'server_time' => $this->serverTimeBlock($now),
            'verdict' => $verdict,
            'looking_at_phone' => 'unknown',
            'last_activity' => [] !== $presentAges ? $this->relativeBlock(min($presentAges)) : $this->nullRelativeBlock(),
            'location' => [
                'place' => $place['place'],
                'source' => $place['source'],
                'note' => $place['note'],
                'lat' => $location['latitude'] ?? null,
                'lon' => $location['longitude'] ?? null,
                'age_relative' => null !== $locationAge ? $this->relativeTime($locationAge) : null,
            ],
            'wearables' => [
                'band' => [
                    'hr_bpm' => $heartRate['bpm'] ?? null,
                    'hr_age_relative' => null !== $hrAge ? $this->relativeTime($hrAge) : null,
                ],
            ],
            'activity' => [
                'steps_today' => $stepsToday['steps'] ?? null,
                'intensity' => 'unknown',
                'in_motion' => null,
            ],
            'bt_devices' => [],
            'fallback' => [
                'reason' => 'no live data',
                'last_auto_sync' => null !== $syncAge ? $this->relativeBlock($syncAge) : null,
            ],
        ];
    }

    /**
     * The real current date/time, first in every response. Exists because
     * the calling LLM's own sense of "today" can't be trusted — model
     * training cutoffs and (per Stan) some providers' models run in an
     * offset frame — so this is the one field to override that belief
     * with, rather than letting an LLM reason about `age_relative`/staleness
     * against its own assumed date.
     *
     * @return array{iso: string, timezone: string, unix: int}
     */
    private function serverTimeBlock(\DateTimeImmutable $now): array
    {
        return [
            'iso' => $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format(\DateTimeInterface::ATOM),
            'timezone' => self::TIMEZONE,
            'unix' => $now->getTimestamp(),
        ];
    }

    private function ageSeconds(?int $tsMs, \DateTimeImmutable $now): ?int
    {
        return null === $tsMs ? null : $now->getTimestamp() - intdiv($tsMs, 1000);
    }

    private function statusOf(?int $ageSeconds, int $freshSeconds, int $staleSeconds): ?string
    {
        if (null === $ageSeconds) {
            return null;
        }

        return match (true) {
            $ageSeconds <= $freshSeconds => 'live',
            $ageSeconds > $staleSeconds => 'stale',
            default => 'recent',
        };
    }

    /**
     * @param string[] $presentStatuses
     * @param int[] $presentAges
     */
    private function overallVerdict(array $presentStatuses, array $presentAges): string
    {
        if ([] === $presentStatuses) {
            return 'blind';
        }
        if (in_array('live', $presentStatuses, true)) {
            return 'live';
        }
        if (in_array('recent', $presentStatuses, true)) {
            return 'recent';
        }

        // Every present signal is 'stale' — still distinguish "old but
        // present" from "nothing under 24h", per the spec's blind rule.
        return min($presentAges) > self::BLIND_SECONDS ? 'blind' : 'stale';
    }

    /**
     * Rule 4: never infer intensity from stale/missing HR, except
     * `running` which stands on its own regardless of HR freshness.
     */
    private function intensityOf(?string $activityType, ?int $hrBpm, ?string $hrStatus): string
    {
        if ('running' === $activityType) {
            return 'high';
        }

        $hrFresh = null !== $hrBpm && in_array($hrStatus, ['live', 'recent'], true);
        if (!$hrFresh) {
            return 'unknown';
        }

        return match (true) {
            $hrBpm > 135 => 'high',
            in_array($activityType, ['stationary', 'still'], true) && $hrBpm < 90 => 'resting',
            'walking' === $activityType && $hrBpm >= 90 && $hrBpm <= 110 => 'light',
            'walking' === $activityType && $hrBpm > 110 && $hrBpm <= 135 => 'brisk',
            default => 'unknown',
        };
    }

    private function inMotionOf(?string $activityType): ?bool
    {
        return match ($activityType) {
            'walking', 'running', 'on_bicycle' => true,
            'stationary', 'still' => false,
            default => null,
        };
    }

    /**
     * Rule 2: WiFi-first (instant, room-precise) before GPS/geofence
     * (drifts, can be slow/absent indoors). NamedLocation.wifiSsid is
     * null until populated via /admin — an empty map just falls straight
     * through to the existing geofence match (App\Controller\Admin\
     * GetCurrentPlaceTool uses the same haversine-radius logic).
     *
     * `note` is a separate, looser hint alongside `place`/`source`: the
     * single nearest NamedLocation by straight-line distance, ignoring
     * radiusMeters entirely (per Stan: "disregard the radii"). It's
     * present whenever coordinates exist, even when `place` itself comes
     * back "unknown" (nothing was close enough to claim as a confident
     * match) — a rough "closest known point" is still useful context then.
     *
     * @return array{place: string, source: ?string, note: ?string}
     */
    private function resolvePlace(?string $wifiSsid, ?float $lat, ?float $lon): array
    {
        $places = $this->namedLocations->findAll();
        $note = $this->closestPlaceNote($places, $lat, $lon);

        if (null !== $wifiSsid) {
            foreach ($places as $place) {
                if (null !== $place->getWifiSsid() && $place->getWifiSsid() === $wifiSsid) {
                    return ['place' => $place->getName(), 'source' => 'wifi', 'note' => $note];
                }
            }
        }

        if (null === $lat || null === $lon) {
            return ['place' => 'unknown', 'source' => null, 'note' => $note];
        }

        $current = new Coordinate($lat, $lon);
        $haversine = new Haversine();
        foreach ($places as $place) {
            $placeCoord = new Coordinate($place->getLatitude(), $place->getLongitude());
            if ($haversine->getDistance($current, $placeCoord) <= $place->getRadiusMeters()) {
                return ['place' => $place->getName(), 'source' => 'geofence', 'note' => $note];
            }
        }

        return ['place' => 'unknown', 'source' => 'gps', 'note' => $note];
    }

    /**
     * @param NamedLocation[] $places
     */
    private function closestPlaceNote(array $places, ?float $lat, ?float $lon): ?string
    {
        if (null === $lat || null === $lon || [] === $places) {
            return null;
        }

        $current = new Coordinate($lat, $lon);
        $haversine = new Haversine();

        $closest = null;
        $closestDistance = null;
        foreach ($places as $place) {
            $distance = $haversine->getDistance($current, new Coordinate($place->getLatitude(), $place->getLongitude()));
            if (null === $closestDistance || $distance < $closestDistance) {
                $closestDistance = $distance;
                $closest = $place;
            }
        }

        return sprintf('location is closest to "%s"', $closest->getName());
    }

    /**
     * `connectivity.wifi_ssid` matched against NamedNetwork.ssid
     * (case-insensitive), same pattern as bt_devices matching: the raw
     * `wifi_ssid` field is untouched, this only *adds* `wifi_label` (the
     * human name, e.g. "Bedroom") and `wifi_note` alongside it in
     * `connectivity`, both `null` when nothing matches or there's no SSID
     * (on mobile data, or WiFi without location permission).
     *
     * @return array{label: ?string, note: ?string}
     */
    private function resolveNetwork(?string $wifiSsid): array
    {
        if (null === $wifiSsid) {
            return ['label' => null, 'note' => null];
        }

        foreach ($this->namedNetworks->findAll() as $network) {
            if (0 === strcasecmp($network->getSsid(), $wifiSsid)) {
                return ['label' => $network->getName(), 'note' => $network->getNotes()];
            }
        }

        return ['label' => null, 'note' => null];
    }

    /**
     * Every raw bt_devices[] entry (`name`, `address`, `rssi_dbm`, `ts`)
     * passes through unchanged; matching against NamedBluetoothDevice (by
     * MAC address first, then broadcast name) only *adds* `label` (the
     * human name, e.g. "Nissan Leaf") and `note` (the free-text Stan wrote
     * for it, e.g. "Stan is driving the Nissan Leaf"), both `null` when
     * nothing matches.
     *
     * @param array<int, array<string, mixed>> $rawDevices
     * @return array<int, array<string, mixed>>
     */
    private function resolveBtDevices(array $rawDevices): array
    {
        if ([] === $rawDevices) {
            return [];
        }

        $known = $this->namedBluetoothDevices->findAll();

        return array_map(fn (array $device): array => $this->matchBtDevice($device, $known), $rawDevices);
    }

    /**
     * @param array<string, mixed> $device
     * @param NamedBluetoothDevice[] $known
     * @return array<string, mixed>
     */
    private function matchBtDevice(array $device, array $known): array
    {
        $address = $device['address'] ?? null;
        $name = $device['name'] ?? null;

        foreach ($known as $entry) {
            $matchesMac = null !== $address && null !== $entry->getMacAddress() && 0 === strcasecmp($entry->getMacAddress(), $address);
            $matchesName = null !== $name && null !== $entry->getDeviceName() && 0 === strcasecmp($entry->getDeviceName(), $name);
            if ($matchesMac || $matchesName) {
                return $device + ['label' => $entry->getName(), 'note' => $entry->getNotes()];
            }
        }

        return $device + ['label' => null, 'note' => null];
    }

    /**
     * @return array{relative: string, hours: float, days: float}
     */
    private function relativeBlock(int $seconds): array
    {
        return [
            'relative' => $this->relativeTime($seconds),
            'hours' => round($seconds / 3600, 2),
            'days' => round($seconds / 86400, 2),
        ];
    }

    /**
     * @return array{relative: null, hours: null, days: null}
     */
    private function nullRelativeBlock(): array
    {
        return ['relative' => null, 'hours' => null, 'days' => null];
    }

    /**
     * Precise, compact "ago" text — e.g. "44s ago", "12m ago",
     * "3h 14m ago", "2d 5h ago". Age is always computed from epoch
     * seconds (see ageSeconds()), so this is timezone-agnostic by
     * construction — it's a duration, not a wall-clock time, and stays
     * correct regardless of what timezone `server_time` displays "now" in.
     */
    private function relativeTime(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds ago', $seconds);
        }
        if ($seconds < 3600) {
            return sprintf('%dm ago', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            return sprintf('%dh %dm ago', $hours, $minutes);
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        return sprintf('%dd %dh ago', $days, $hours);
    }
}
