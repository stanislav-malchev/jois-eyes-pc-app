<?php

namespace App\MCP\Tools;

use App\Backdoor\CachedSnapshot;
use App\Backdoor\LiveVitalsResolver;
use App\Backdoor\SnapshotClient;
use App\Repository\NamedLocationRepository;
use App\Repository\RecordRepository;
use KLP\KlpMcpServer\Services\ProgressService\ProgressNotifierInterface;
use KLP\KlpMcpServer\Services\ToolService\Annotation\ToolAnnotation;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use KLP\KlpMcpServer\Services\ToolService\Result\ToolResultInterface;
use KLP\KlpMcpServer\Services\ToolService\Schema\PropertyType;
use KLP\KlpMcpServer\Services\ToolService\Schema\SchemaProperty;
use KLP\KlpMcpServer\Services\ToolService\Schema\StructuredSchema;
use KLP\KlpMcpServer\Services\ToolService\StreamableToolInterface;
use Location\Coordinate;
use Location\Distance\Haversine;

/**
 * "The Watcher" — one call answering Stan's whole presence state, per the
 * LLM wiki's concepts/current-state-tool.md (drafted 28.08.2026, built
 * 29.08.2026). Composes the-breath's cached snapshot (App\Scheduler\
 * PollBackdoorMessageHandler, zero radio wake) with the stored records DB,
 * rather than adding an 8th narrow tool.
 *
 * Data source priority, per the spec:
 *   1. Cached breath (var/breath_state.json's `last_snapshot`) — instant,
 *      no network call, refreshed every ~3 min by the-breath.
 *   2. On-demand backdoor — only when force_steps/force_hr is set, one
 *      live SnapshotClient::fetch() (one radio wake, refreshes every field
 *      at once — "one request = one radio wake" per the contract, not
 *      just the forced one).
 *   3. joiseyes DB, via LiveVitalsResolver — only reached when the-breath
 *      has literally never cached a snapshot (fresh install / broken
 *      daemon); the ordinary "cache is old" case is handled entirely
 *      within branch 1's own freshness math (verdict degrades to
 *      stale/blind, it doesn't fall through to the DB).
 *
 * Known v1 simplification, documented rather than silently skipped:
 * `in_motion`/`intensity`'s "high step delta" escape hatch (spec rule 5)
 * needs a steps_since_boot delta tracked across polls, which the-breath
 * doesn't persist yet — intensity/in_motion here are derived from
 * activity.type + fresh HR only, per the rest of the rule table.
 */
class CurrentStateTool implements StreamableToolInterface
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
        private readonly string $stateFile,
    ) {
    }

    public function getName(): string
    {
        return 'current-state';
    }

    public function getDescription(): string
    {
        return 'Returns Stan\'s whole presence state as one document: verdict (live/recent/stale/blind), looking_at_phone, location/place, band+heart rate, and activity/steps. Reads the-breath\'s cached snapshot by default (instant, no radio wake); pass force_steps or force_hr for a fresh on-demand phone read.';
    }

    public function getInputSchema(): StructuredSchema
    {
        return new StructuredSchema(
            new SchemaProperty(
                name: 'force_steps',
                type: PropertyType::BOOLEAN,
                description: 'Trigger a fresh on-demand read from the phone (one radio wake) instead of the cached breath, for an up-to-the-second steps/activity answer.',
                default: 'false',
            ),
            new SchemaProperty(
                name: 'force_hr',
                type: PropertyType::BOOLEAN,
                description: 'Trigger a fresh on-demand read from the phone (one radio wake) instead of the cached breath, for an up-to-the-second band heart rate reading.',
                default: 'false',
            ),
        );
    }

    public function getOutputSchema(): ?StructuredSchema
    {
        return null;
    }

    public function getAnnotations(): ToolAnnotation
    {
        return new ToolAnnotation(
            title: 'Current state (The Watcher)',
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $now = new \DateTimeImmutable();
        $forceLive = (bool) ($arguments['force_steps'] ?? false) || (bool) ($arguments['force_hr'] ?? false);

        $snapshot = $forceLive ? $this->fetchLiveSnapshot($now) : null;
        $snapshot ??= $this->readCachedSnapshot();

        if (null === $snapshot || $this->hasNoSignals($snapshot)) {
            return $this->buildFromDbOnly($now);
        }

        return $this->buildFromSnapshot($snapshot, $now);
    }

    public function isStreaming(): bool
    {
        return false;
    }

    public function setProgressNotifier(ProgressNotifierInterface $progressNotifier): void
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLiveSnapshot(\DateTimeImmutable $now): ?array
    {
        $raw = $this->snapshotClient->fetch();

        return $raw ? CachedSnapshot::fromRaw($raw, $now) : null;
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
     * @param array<string, mixed> $snapshot
     */
    private function hasNoSignals(array $snapshot): bool
    {
        return null === ($snapshot['screen']['last_unlocked_ts'] ?? null)
            && null === ($snapshot['location']['ts'] ?? null)
            && null === ($snapshot['activity']['steps_ts'] ?? null)
            && null === ($snapshot['band']['hr_ts'] ?? null);
    }

    /**
     * Branch 1: a cached or freshly-fetched snapshot is available.
     * Every field's freshness is judged independently by its own *_ts —
     * an old cache still answers, it just degrades the verdict, rather
     * than falling through to the DB (the DB has no screen/wifi/activity-
     * type data to fall back to anyway — those simply aren't Records).
     *
     * @param array<string, mixed> $snapshot
     */
    private function buildFromSnapshot(array $snapshot, \DateTimeImmutable $now): StructuredToolResult
    {
        $screenOn = $snapshot['screen']['on'] ?? null;
        $unlockedAge = $this->ageSeconds($snapshot['screen']['last_unlocked_ts'] ?? null, $now);
        $locationAge = $this->ageSeconds($snapshot['location']['ts'] ?? null, $now);
        $stepsAge = $this->ageSeconds($snapshot['activity']['steps_ts'] ?? null, $now);
        $hrAge = $this->ageSeconds($snapshot['band']['hr_ts'] ?? null, $now);

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

        $place = $this->resolvePlace(
            $snapshot['wifi_ssid'] ?? null,
            $snapshot['location']['lat'] ?? null,
            $snapshot['location']['lon'] ?? null,
        );

        $activityType = $snapshot['activity']['type'] ?? null;
        $hrBpm = $snapshot['band']['hr_bpm'] ?? null;

        return new StructuredToolResult([
            'verdict' => $verdict,
            'last_activity' => [] !== $presentAges ? $this->relativeBlock(min($presentAges)) : $this->nullRelativeBlock(),
            'looking_at_phone' => $lookingAtPhone,
            'screen' => [
                'on' => $screenOn,
                'last_unlocked_ts' => $snapshot['screen']['last_unlocked_ts'] ?? null,
            ],
            'location' => [
                'place' => $place['place'],
                'source' => $place['source'],
                'wifi_ssid' => $snapshot['wifi_ssid'] ?? null,
                'lat' => $snapshot['location']['lat'] ?? null,
                'lon' => $snapshot['location']['lon'] ?? null,
                'age_relative' => null !== $locationAge ? $this->relativeTime($locationAge) : null,
            ],
            'band' => [
                'connected' => $snapshot['band']['connected'] ?? null,
                'hr_bpm' => $hrBpm,
                'hr_age_relative' => null !== $hrAge ? $this->relativeTime($hrAge) : null,
            ],
            'activity' => [
                'type' => $activityType,
                'steps_today' => $snapshot['activity']['steps_today'] ?? null,
                'steps_age_relative' => null !== $stepsAge ? $this->relativeTime($stepsAge) : null,
                'intensity' => $this->intensityOf($activityType, $hrBpm, $hrStatus),
                'in_motion' => $this->inMotionOf($activityType),
            ],
            'fallback' => null,
        ]);
    }

    /**
     * Branch 2: the-breath has never successfully cached a snapshot at
     * all (fresh install, or the breath service is down and has been
     * since before this process started). Falls back to
     * LiveVitalsResolver (itself live-then-DB) for location/heart
     * rate/steps, and to RecordRepository::latestReceivedAt() for the
     * spec's `fallback.last_auto_sync`. screen/wifi/activity-type have no
     * DB equivalent — they stay null/unknown, honestly, rather than
     * guessed.
     */
    private function buildFromDbOnly(\DateTimeImmutable $now): StructuredToolResult
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

        $place = $location ? $this->resolvePlace(null, $location['latitude'], $location['longitude']) : ['place' => 'unknown', 'source' => null];

        return new StructuredToolResult([
            'verdict' => $verdict,
            'last_activity' => [] !== $presentAges ? $this->relativeBlock(min($presentAges)) : $this->nullRelativeBlock(),
            'looking_at_phone' => 'unknown',
            'screen' => ['on' => null, 'last_unlocked_ts' => null],
            'location' => [
                'place' => $place['place'],
                'source' => $place['source'],
                'wifi_ssid' => null,
                'lat' => $location['latitude'] ?? null,
                'lon' => $location['longitude'] ?? null,
                'age_relative' => null !== $locationAge ? $this->relativeTime($locationAge) : null,
            ],
            'band' => [
                'connected' => null,
                'hr_bpm' => $heartRate['bpm'] ?? null,
                'hr_age_relative' => null !== $hrAge ? $this->relativeTime($hrAge) : null,
            ],
            'activity' => [
                'type' => null,
                'steps_today' => $stepsToday['steps'] ?? null,
                'steps_age_relative' => null,
                'intensity' => 'unknown',
                'in_motion' => null,
            ],
            'fallback' => [
                'reason' => 'no live data',
                'last_auto_sync' => null !== $syncAge ? $this->relativeBlock($syncAge) : null,
            ],
        ]);
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
     * @return array{place: string, source: ?string}
     */
    private function resolvePlace(?string $wifiSsid, ?float $lat, ?float $lon): array
    {
        $places = $this->namedLocations->findAll();

        if (null !== $wifiSsid) {
            foreach ($places as $place) {
                if (null !== $place->getWifiSsid() && $place->getWifiSsid() === $wifiSsid) {
                    return ['place' => $place->getName(), 'source' => 'wifi'];
                }
            }
        }

        if (null === $lat || null === $lon) {
            return ['place' => 'unknown', 'source' => null];
        }

        $current = new Coordinate($lat, $lon);
        $haversine = new Haversine();
        foreach ($places as $place) {
            $placeCoord = new Coordinate($place->getLatitude(), $place->getLongitude());
            if ($haversine->getDistance($current, $placeCoord) <= $place->getRadiusMeters()) {
                return ['place' => $place->getName(), 'source' => 'geofence'];
            }
        }

        return ['place' => 'unknown', 'source' => 'gps'];
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

    private function relativeTime(int $seconds): string
    {
        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);

            return sprintf('%d min%s ago', $minutes, 1 === $minutes ? '' : 's');
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);

            return sprintf('%d hour%s ago', $hours, 1 === $hours ? '' : 's');
        }
        $days = intdiv($seconds, 86400);

        return sprintf('%d day%s ago', $days, 1 === $days ? '' : 's');
    }
}
