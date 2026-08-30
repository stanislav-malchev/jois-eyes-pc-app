<?php

namespace App\Service\PcState;

/**
 * Tier-1 "question" in the presence check ladder the LLM wiki's
 * concepts/pc-presence-agent.md describes: only worth calling after a
 * cheap tier-0 glance (a raw `GET /idle` straight to the WindowsEyes agent,
 * outside this service) already suggests Stan is at the desk. This tool
 * proxies that same agent through App\Service\PcState\PcStateClient and
 * adds decorations — same "raw fields untouched, decorations added
 * alongside" rule as App\Service\CurrentState\CurrentStateResolver, at a
 * much smaller scale: today the agent only has `idle_seconds` to give.
 *
 * Deliberately NOT merged into CurrentStateResolver's document — that
 * would force a phone-radio wake and a GPS fix on every cheap desk glance,
 * defeating the whole point of the tiered design.
 *
 * Milestone-2 agent fields (`lock_state`, `session`/`attached_to_desktop`,
 * `app_category`, ...) aren't decorated yet — there's nothing to derive
 * from them until the agent's `/v1/pc-state` route ships them. Because raw
 * agent fields pass through untouched (see resolve()), they'll simply
 * start appearing here the day the agent adds them, with no shape change
 * needed on this side; decorating them is future work, not blocked by
 * anything here.
 *
 * One deliberate exception to "raw fields pass through untouched": the
 * agent's own `/idle` response gained its own `server_time` block
 * (30.08.2026, milestone 2) — this tool's top-level `server_time` is
 * always the wsl-server host's own clock, the same sitewide anchor
 * convention CurrentStateResolver uses, so the two would collide on the
 * same key. The agent's reading is kept, not dropped, renamed to
 * `agent_server_time` instead of overwriting ours. `ts` (the agent's
 * epoch-ms reading time) has no such collision and passes through as-is.
 */
class PcStateResolver
{
    private const TIMEZONE = 'Europe/Sofia';

    // The desk-presence thresholds from the wiki page's decoration spec,
    // in seconds — mirrors the freshness-threshold philosophy in
    // App\Service\CurrentState\CurrentStateResolver / concepts/freshness.md.
    private const AT_DESK = 2 * 60;
    private const STEPPED_AWAY = 10 * 60;
    private const AWAY = 60 * 60;

    public function __construct(
        private readonly PcStateClient $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $now = new \DateTimeImmutable();
        $raw = $this->client->fetchIdle();

        if (null === $raw) {
            return [
                'server_time' => $this->serverTimeBlock($now),
                'reachable' => false,
                'idle_seconds' => null,
                'idle_relative' => null,
                'verdict' => null,
                'agent_server_time' => null,
            ];
        }

        $idleSeconds = $raw['idle_seconds'] ?? null;
        $agentServerTime = $raw['server_time'] ?? null;
        unset($raw['server_time']);

        return [
            'server_time' => $this->serverTimeBlock($now),
            'reachable' => true,
            'idle_relative' => null !== $idleSeconds ? $this->relativeTime($idleSeconds) : null,
            'verdict' => $this->verdictOf($idleSeconds),
            'agent_server_time' => $agentServerTime,
        ] + $raw;
    }

    /**
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

    private function verdictOf(?int $idleSeconds): ?string
    {
        if (null === $idleSeconds) {
            return null;
        }

        return match (true) {
            $idleSeconds < self::AT_DESK => 'at_desk',
            $idleSeconds < self::STEPPED_AWAY => 'stepped_away',
            $idleSeconds < self::AWAY => 'away',
            default => 'desk_asleep',
        };
    }

    /**
     * Same compact "ago" text as CurrentStateResolver::relativeTime() —
     * duplicated rather than shared, since it's a single small pure
     * function and the two resolvers otherwise have no reason to depend on
     * each other.
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
