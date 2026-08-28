<?php

namespace App\Service\Consolidation;

/**
 * Which app's data wins when two records of the same type genuinely
 * conflict over the same real-world time window (e.g. Samsung Health's
 * daily Steps rollup vs. Health Connect's own phone-sensor bursts —
 * confirmed 28.08.2026 to be two independent measurements whose sums don't
 * even match, not a wide record containing the narrow ones).
 *
 * Decided 29.08.2026 with Stan: Health Connect's own bridge is authoritative
 * over other apps' overlapping rollups. Kept as an inspectable prefix table
 * (mirroring the Xiaomi import's SOURCE_PRIORITY) rather than an if/else so
 * it can be revised — e.g. ranking two non-Health-Connect apps against each
 * other — without touching OverlapResolver's logic.
 */
final class DataOriginPriority
{
    private const PRIORITIES = [
        'com.android.healthconnect' => 10,
    ];

    private const DEFAULT_PRIORITY = 0;

    public function priorityOf(?string $dataOrigin): int
    {
        if ($dataOrigin === null) {
            return self::DEFAULT_PRIORITY;
        }

        foreach (self::PRIORITIES as $prefix => $priority) {
            if (str_starts_with($dataOrigin, $prefix)) {
                return $priority;
            }
        }

        return self::DEFAULT_PRIORITY;
    }
}
