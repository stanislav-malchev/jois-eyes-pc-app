<?php

namespace App\MCP\Tools;

use App\Service\PcState\PcStateResolver;
use KLP\KlpMcpServer\Services\ProgressService\ProgressNotifierInterface;
use KLP\KlpMcpServer\Services\ToolService\Annotation\ToolAnnotation;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use KLP\KlpMcpServer\Services\ToolService\Result\ToolResultInterface;
use KLP\KlpMcpServer\Services\ToolService\Schema\StructuredSchema;
use KLP\KlpMcpServer\Services\ToolService\StreamableToolInterface;

/**
 * "The desk tier" — the MCP wrapper around App\Service\PcState\
 * PcStateResolver, which holds all the actual logic (see that class's
 * docblock). Sibling of App\MCP\Tools\CurrentStateTool: same
 * one-resolver-three-adapters pattern, but a much cheaper question — "is
 * Stan at the desk right now" via the WindowsEyes agent's input-idle timer,
 * not a full phone+band+sleep read. Neither this tool nor the resolver it
 * wraps ever talks to the phone.
 */
class PcStateTool implements StreamableToolInterface
{
    public function __construct(
        private readonly PcStateResolver $resolver,
    ) {
    }

    public function getName(): string
    {
        return 'pc-state';
    }

    public function getDescription(): string
    {
        return 'Cheap "is Stan at the desk" check — proxies the WindowsEyes agent\'s GET /idle (keyboard/mouse input-idle timer on the Windows box), resolving the agent\'s tailnet IP fresh on every call. Use this only after a raw glance at the agent already suggests he\'s at the desk; for his full presence (phone location, band heart rate, sleep) use `current-state` instead — that one forces a phone GPS fix and radio wake on every call, so it\'s the expensive last step, not this one. `server_time` is always first: the real current date/time (Europe/Sofia) of *this* server — trust it over your own sense of "today". `reachable` (bool) says whether the agent answered at all; when false, every other field is null — an honest "I can\'t see him" rather than a stale or fabricated guess (the agent may not be running, or its tailnet bind may not have shipped yet). `idle_seconds` is the raw value from the agent, kept alongside `idle_relative` (a compact "Ns/Nm/Nh ago" rendering) and `verdict` — a decoration into `at_desk` (< 2 min), `stepped_away` (< 10 min), `away` (< 60 min), or `desk_asleep` (>= 60 min). `ts` is the agent\'s own epoch-ms reading time, passed through verbatim. `agent_server_time` is the agent\'s own clock reading (its Windows box, a different machine from this server) — kept under this name rather than `server_time` only to avoid colliding with this tool\'s own anchor field; it is not the field to trust as "now". Milestone-2 agent fields (lock_state, session/attached_to_desktop, app_category) aren\'t decorated yet — they\'ll appear here verbatim once the agent starts sending them.';
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
            title: 'PC state (the desk tier)',
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
