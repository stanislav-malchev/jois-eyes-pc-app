<?php

namespace App\Service\CurrentState;

use App\Backdoor\LiveVitalsResolver;
use App\Backdoor\SnapshotClient;
use App\Entity\NamedBluetoothDevice;
use App\Entity\NamedLocation;
use App\Enum\RecordType;
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
 * `connectivity.wifi_label`, `wearables.band.avg_hr_today` — folded in
 * 29.08.2026 from the retired `daily-vitals-summary` MCP tool, the one
 * thing it did that a live/last-known `hr_bpm` reading can't, see
 * averageHeartRateToday() — etc.), nothing ever removed, renamed, or
 * reshaped. `source` (`"live"|"db"`) says which of the two branches below
 * actually answered — added after Stan saw a `stale` verdict on a call
 * he'd just made and (reasonably) asked whether it was cached; it wasn't
 * (there's no cache anymore at all — see below), but there was no way to
 * *see* that before `source` existed. `verdict` still judges freshness
 * independently of `source` — a `"live"` `source` with a `stale` `verdict`
 * is normal, not a contradiction; see the field derivation notes below.
 *
 * Data source priority — rebuilt again 29.08.2026, retiring [[the-breath]]
 * entirely (its Symfony Scheduler/Messenger worker, `joiseyes-breath.service`,
 * and its `var/breath_state.json` cache): per Stan, that PC-side cadence
 * poller had become pointless once every consumer either wants an
 * on-demand live read (this resolver) or the phone's own 15-min WorkManager
 * archival sync (unrelated to this tool) — there was no longer a real job
 * left for a 3rd, in-between "keep a warm cache" poller to do, and Stan
 * explicitly doesn't want us nudging the phone to sync any more often than
 * its own 15-min floor:
 *   1. Live — always attempted first, unconditionally: a real
 *      SnapshotClient::fetchFresh() call to the phone (`gps=fix`+`bt=scan`,
 *      forcing a real sensor read instead of last-known values), every
 *      single call. Known trade-off, deliberate: this wakes the phone's
 *      radio *and* forces a GPS fix on every call. **`source: "live"`
 *      still doesn't mean every field is fresh** —
 *      `activity.steps_today`/`wearables.band.hr_bpm` have no on-demand
 *      "force" param at all (only location/BT do); they're always
 *      whatever the phone app already has in memory, live call or not.
 *   2. joiseyes DB — only when the live call fails (phone asleep, tailnet
 *      down, rate-limited — there is no intermediate cache to fall back to
 *      first anymore). Falls back to App\Backdoor\LiveVitalsResolver for
 *      location/heart rate/steps (itself live-then-DB) and
 *      RecordRepository::latestReceivedAt() for `fallback.last_auto_sync`.
 *      **A completely different, reduced, synthetic shape** — there is no
 *      raw snapshot document to reflect when this branch is reached, so
 *      most raw sections (power/dnd/environment/health_connect/
 *      connectivity/bt_devices/screen/activity.type) are simply absent,
 *      honestly, not guessed. Callers must not assume this shape matches
 *      the live one field-for-field.
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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $now = new \DateTimeImmutable();

        $raw = $this->snapshotClient->fetchFresh();
        if (null !== $raw) {
            return $this->decorate($raw, $now);
        }

        return $this->buildFromDbOnly($now);
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
        $decorated['wearables']['band']['avg_hr_today'] = $this->averageHeartRateToday();
        $decorated['connectivity']['wifi_label'] = $network['label'];
        $decorated['connectivity']['wifi_note'] = $network['note'];
        $decorated['bt_devices'] = $this->resolveBtDevices($raw['bt_devices'] ?? []);

        return [
            'server_time' => $this->serverTimeBlock($now),
            'verdict' => $verdict,
            'source' => 'live',
            'looking_at_phone' => $lookingAtPhone,
            'last_activity' => [] !== $presentAges ? $this->relativeBlock(min($presentAges)) : $this->nullRelativeBlock(),
            'sleep' => $this->lastNightSleep($now),
        ] + $decorated + ['fallback' => null];
    }

    /**
     * Branch 2: the live phone read failed — phone asleep, tailnet down,
     * rate-limited, or genuinely gone. There is no intermediate cache
     * anymore (see class docblock), so this is reached directly, not as a
     * last resort after a cache miss. Necessarily a reduced, synthetic
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
            'source' => 'db',
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
                    'avg_hr_today' => $this->averageHeartRateToday(),
                ],
            ],
            'activity' => [
                'steps_today' => $stepsToday['steps'] ?? null,
                'intensity' => 'unknown',
                'in_motion' => null,
            ],
            'sleep' => $this->lastNightSleep($now),
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

    /**
     * `wearables.band.avg_hr_today` — folded in from the retired
     * `daily-vitals-summary` MCP tool (29.08.2026): the one thing that
     * tool did that this one couldn't — `hr_bpm` is always a single
     * live/last-known reading, never a daily average. DB-only by nature
     * (an average needs the whole day's ingested records, not a snapshot),
     * so computed the same way regardless of `source` (live or db).
     *
     * Weighted mean of each HeartRate record's cached average
     * (RecordMetricsExtractor), weighted by its sample count — equivalent
     * to averaging every individual sample directly (see the LLM wiki's
     * concepts/record-metrics-cache.md) since no HeartRate row's window
     * crosses a day boundary, without decoding payload_json per row.
     */
    private function averageHeartRateToday(): ?int
    {
        $startOfDay = (new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new \DateTimeZone('UTC'));

        $weightedSum = 0.0;
        $totalSamples = 0;
        foreach ($this->records->findByTypeSince(RecordType::HEART_RATE->value, $startOfDay) as $record) {
            if (null === $record->getMetricValue()) {
                continue;
            }
            $n = $record->getSampleCount() ?? 1;
            $weightedSum += $record->getMetricValue() * $n;
            $totalSamples += $n;
        }

        return 0 === $totalSamples ? null : (int) round($weightedSum / $totalSamples);
    }

    /**
     * `sleep` — the most recent sleep session, straight from the DB.
     * Present in both the live and DB-only branches identically: sleep
     * isn't part of the phone's /v1/snapshot contract at all, so there's
     * no live reading to prefer over it either way. Not one of
     * RecordMetricsExtractor's cached types (only Steps/HeartRate are), so
     * this decodes payload_json directly.
     *
     * @return array{start_time: ?string, end_time: ?string, duration_minutes: ?int, duration_formatted: ?string, stages_minutes: ?array<string, int>, ended_relative: ?string}
     */
    private function lastNightSleep(\DateTimeImmutable $now): array
    {
        $record = $this->records->createQueryBuilder('r')
            ->andWhere('r.type IN (:types)')
            ->andWhere('r.deleted = false')
            ->setParameter('types', RecordType::variants(RecordType::SLEEP->value))
            ->orderBy('r.startTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $record) {
            return [
                'start_time' => null,
                'end_time' => null,
                'duration_minutes' => null,
                'duration_formatted' => null,
                'stages_minutes' => null,
                'ended_relative' => null,
            ];
        }

        $payload = $record->getPayload();
        $startTime = new \DateTimeImmutable($payload['startTime']);
        $endTime = new \DateTimeImmutable($payload['endTime']);
        $durationMinutes = (int) round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);

        $stagesMinutes = [];
        foreach ($payload['stages'] ?? [] as $stage) {
            $stageStart = new \DateTimeImmutable($stage['startTime']);
            $stageEnd = new \DateTimeImmutable($stage['endTime']);
            $stageMinutes = (int) round(($stageEnd->getTimestamp() - $stageStart->getTimestamp()) / 60);
            $stageType = $stage['stage'] ?? 'unknown';
            $stagesMinutes[$stageType] = ($stagesMinutes[$stageType] ?? 0) + $stageMinutes;
        }

        return [
            'start_time' => $startTime->format(\DateTimeInterface::ATOM),
            'end_time' => $endTime->format(\DateTimeInterface::ATOM),
            'duration_minutes' => $durationMinutes,
            'duration_formatted' => sprintf('%dh %dm', intdiv($durationMinutes, 60), $durationMinutes % 60),
            'stages_minutes' => $stagesMinutes,
            'ended_relative' => $this->relativeTime(max(0, $now->getTimestamp() - $endTime->getTimestamp())),
        ];
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
        if (null === $lat || null === $lon) {
            return null;
        }

        $closest = $this->namedLocations->findClosestAmong($places, $lat, $lon);

        return null !== $closest ? sprintf('location is closest to "%s"', $closest->getName()) : null;
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
