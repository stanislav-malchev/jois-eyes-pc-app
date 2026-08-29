<?php

namespace App\Backdoor;

/**
 * Passes a raw /v1/snapshot document through untouched, just tagging it
 * with when it was cached. Written by App\Scheduler\PollBackdoorMessageHandler
 * (the 3-min cadence poll) into var/breath_state.json's `last_snapshot`, and
 * read back by App\Service\CurrentState\CurrentStateResolver as its
 * fallback source when a live phone read fails.
 *
 * Deliberately NOT a trim anymore (an earlier version reduced this to a
 * handful of fields) — per Stan: the whole point is 100% of the snapshot,
 * live or cached, is always what a caller sees, with our own decorations
 * layered on top rather than a reshaped subset.
 */
final class CachedSnapshot
{
    /**
     * @param array<string, mixed> $snapshot the raw decoded /v1/snapshot body
     * @return array<string, mixed>
     */
    public static function fromRaw(array $snapshot, \DateTimeImmutable $cachedAt): array
    {
        return ['cached_at' => $cachedAt->format(\DateTimeInterface::ATOM)] + $snapshot;
    }
}
