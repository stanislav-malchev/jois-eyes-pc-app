<?php

namespace App\MCP\Tools;

use App\Service\CurrentState\CurrentStateResolver;
use KLP\KlpMcpServer\Services\ProgressService\ProgressNotifierInterface;
use KLP\KlpMcpServer\Services\ToolService\Annotation\ToolAnnotation;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use KLP\KlpMcpServer\Services\ToolService\Result\ToolResultInterface;
use KLP\KlpMcpServer\Services\ToolService\Schema\StructuredSchema;
use KLP\KlpMcpServer\Services\ToolService\StreamableToolInterface;

/**
 * "The Watcher" — the MCP wrapper around App\Service\CurrentState\
 * CurrentStateResolver, which holds all the actual logic (see that
 * class's docblock for the data-source priority and decoration rules).
 * This class only adapts that resolver's plain array to the MCP tool
 * protocol (schema, annotations, StructuredToolResult) — App\Controller\
 * CurrentStateController is the other, plain-HTTP caller of the same
 * resolver, for `GET /api/v1/current-state`. Neither talks to the phone
 * on its own; only the resolver does.
 */
class CurrentStateTool implements StreamableToolInterface
{
    public function __construct(
        private readonly CurrentStateResolver $resolver,
    ) {
    }

    public function getName(): string
    {
        return 'current-state';
    }

    public function getDescription(): string
    {
        return 'Returns Stan\'s whole presence state: the phone\'s entire /v1/snapshot document (every section — meta, power, screen, dnd, location, activity, environment, wearables, health_connect, connectivity, bt_devices), untouched, with decorations added alongside the raw fields (never replacing them). `server_time` is always first: the real current date/time (Europe/Sofia) — read it before anything else and trust it over your own sense of "today". Added: `verdict` (live/recent/stale/blind — judges each field\'s own age, independent of `source`), `source` (live/db — which data source actually answered), `looking_at_phone`, `last_activity`; `location.place`/`source`/`note` (closest-known-place, ignoring geofence radius)/`age_relative`; `screen.note`; `activity.steps_age_relative`/`intensity`/`in_motion`; `wearables.band.hr_age_relative`/`avg_hr_today` (a weighted average across today\'s ingested HeartRate records — the one thing `hr_bpm`\'s single live/last-known reading can\'t give you); `connectivity.wifi_label`/`wifi_note` from My Networks when the SSID matches; each `bt_devices[]` entry gets `label`/`note` from My BT Devices when matched; `sleep` (`start_time`/`end_time`/`duration_minutes`/`duration_formatted`/`stages_minutes`/`ended_relative`) — the most recent sleep session, straight from stored records (sleep isn\'t part of the phone\'s live snapshot at all, so this is DB-only and identical whether `source` is live or db). Always forces a fresh live read from the phone first (a real GPS fix + BT scan, not last-known values); falls back straight to stored records if the phone is unreachable (`source: "db"` — a completely different, reduced shape, not a stale copy of the live one) — but even a live read can carry old `activity`/`wearables.band` readings, since those have no on-demand refresh, only location/BT do. Identical output to `GET /api/v1/current-state`.';
    }

    public function getInputSchema(): StructuredSchema
    {
        return new StructuredSchema();
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
        return new StructuredToolResult($this->resolver->resolve());
    }

    public function isStreaming(): bool
    {
        return false;
    }

    public function setProgressNotifier(ProgressNotifierInterface $progressNotifier): void
    {
    }
}
