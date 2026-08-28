<?php

namespace App\Enum;

enum RecordType: string
{
    case PING = 'Ping';
    case LOCATION_FIX = 'LocationFix';
    case HEART_RATE = 'HeartRateRecord';
    case STEPS = 'StepsRecord';
    case SLEEP = 'SleepSessionRecord';
    // Not yet synced by the phone app (no Health Connect ground truth to
    // confirm against) — "OxygenSaturationRecord" is a best guess at the
    // reflective-mapper class name per docs/02-data-model.md, matching the
    // real androidx.health.connect.client.records.OxygenSaturationRecord
    // class and the *Record-suffixed pattern used by the four cases above.
    // Revisit once the app actually syncs this type.
    case SPO2 = 'OxygenSaturationRecord';

    // Xiaomi Mi Fitness CSV import (see ImportXiaomiCsvCommand) — steps,
    // heart_rate, sleep, and spo2 from that source are normalized to the
    // cases above instead of getting their own case; the rest come through
    // verbatim from the CSV's `Key` column.
    case CALORIES = 'calories';
    case DYNAMIC = 'dynamic';
    case INTENSITY = 'intensity';
    case VALID_STAND = 'valid_stand';
    case RESTING_HEART_RATE = 'resting_heart_rate';
    case WATCH_NIGHT_SLEEP = 'watch_night_sleep';
    case SINGLE_HEART_RATE = 'single_heart_rate';
    case SINGLE_STRESS = 'single_stress';
    case STRESS = 'stress';
    case WEIGHT = 'weight';
    case SINGLE_SPO2 = 'single_spo2';

    /**
     * Health Connect self-healed several type strings on 28.08.2026
     * (`HeartRateRecord` -> `HeartRate`, etc. — see docs/04-pc-sync-api.md's
     * known-issue note); rows synced before that date still carry the old
     * long form and won't be touched unless HC reports a fresh change for
     * that exact record, so both forms coexist in `records` indefinitely.
     * This maps either form to the short, canonical one so callers can
     * group/query across both without caring which one a given row has.
     */
    private const LEGACY_ALIASES = [
        self::HEART_RATE->value => 'HeartRate',
        self::STEPS->value => 'Steps',
        self::SLEEP->value => 'SleepSession',
        self::SPO2->value => 'OxygenSaturation',
    ];

    public static function canonicalize(string $type): string
    {
        return self::LEGACY_ALIASES[$type] ?? $type;
    }

    /**
     * @return string[] both type strings a given canonical (or legacy) type
     *                   can appear as in `records`, for use in `type IN (...)`
     */
    public static function variants(string $type): array
    {
        $canonical = self::canonicalize($type);
        $legacy = array_search($canonical, self::LEGACY_ALIASES, true);

        return $legacy === false ? [$canonical] : [$canonical, $legacy];
    }

    public function label(): string
    {
        return match($this) {
            self::PING => 'Ping',
            self::LOCATION_FIX => 'Location Fix',
            self::HEART_RATE => 'Heart Rate',
            self::STEPS => 'Steps',
            self::SLEEP => 'Sleep',
            self::CALORIES => 'Calories',
            self::SPO2 => 'Blood Oxygen (SpO2)',
            self::DYNAMIC => 'Activity Burst',
            self::INTENSITY => 'Activity Intensity',
            self::VALID_STAND => 'Standing Period',
            self::RESTING_HEART_RATE => 'Resting Heart Rate',
            self::WATCH_NIGHT_SLEEP => 'Watch Night Sleep',
            self::SINGLE_HEART_RATE => 'Single Heart Rate Reading',
            self::SINGLE_STRESS => 'Single Stress Reading',
            self::STRESS => 'Stress',
            self::WEIGHT => 'Weight',
            self::SINGLE_SPO2 => 'Single SpO2 Reading',
        };
    }
}
