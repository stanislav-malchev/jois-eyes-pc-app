<?php

namespace App\Service\Consolidation;

/**
 * Which app's data wins when two records of the same type genuinely
 * conflict over the same real-world time window.
 *
 * The priority table is a constructor argument, not a hardcoded constant —
 * corrected 29.08.2026 after verifying against the phone's own Health
 * Connect app, day by day, that the right answer differs by type: Health
 * Connect's own bridge is authoritative for HeartRate/OxygenSaturation
 * (config/services.yaml's default, no override needed), but for Steps
 * Samsung Health (fed by the band) is the correct source and the phone's
 * own on-device sensor is the redundant one — confirmed against real data:
 * Aug 24 (Samsung 9,455 vs phone-bridge 8,147) and Aug 25 (Samsung 1,944 vs
 * phone-bridge 1,194) both matched Health Connect's own displayed total
 * only when Samsung Health won. See config/services.yaml for
 * DailyStepsConsolidator's override and [[per-type-consolidation-semantics]]
 * for why this isn't assumed to generalize across types without checking.
 */
final class DataOriginPriority
{
    private const DEFAULT_PRIORITY = 0;

    /**
     * @param array<string, int> $priorities dataOrigin prefix => priority, checked in declaration order
     */
    public function __construct(
        private readonly array $priorities = ['com.android.healthconnect' => 10],
    ) {
    }

    public function priorityOf(?string $dataOrigin): int
    {
        if ($dataOrigin === null) {
            return self::DEFAULT_PRIORITY;
        }

        foreach ($this->priorities as $prefix => $priority) {
            if (str_starts_with($dataOrigin, $prefix)) {
                return $priority;
            }
        }

        return self::DEFAULT_PRIORITY;
    }
}
