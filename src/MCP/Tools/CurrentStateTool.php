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
        return 'Returns Stan\'s whole presence state: the phone\'s entire /v1/snapshot document (every section — meta, power, screen, dnd, location, activity, environment, wearables, health_connect, connectivity, bt_devices), untouched, with decorations added alongside the raw fields (never replacing them). `server_time` is always first: the real current date/time (Europe/Sofia) — read it before anything else and trust it over your own sense of "today". Added: `verdict` (live/recent/stale/blind), `looking_at_phone`, `last_activity`; `location.place`/`source`/`note` (closest-known-place, ignoring geofence radius)/`age_relative`; `screen.note`; `activity.steps_age_relative`/`intensity`/`in_motion`; `wearables.band.hr_age_relative`; each `bt_devices[]` entry gets `label`/`note` from My BT Devices when matched. Always does one live read from the phone first; falls back to the last cached snapshot, then to stored records, only if the phone is unreachable. Identical output to `GET /api/v1/current-state`.';
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
