<?php

namespace App\Enum;

enum RecordSource: string
{
    case DEBUG = 'debug';
    case LOCATION = 'location';
    case HEALTH_CONNECT = 'health_connect';

    // Xiaomi Mi Fitness CSV import (see ImportXiaomiCsvCommand) — these are
    // the distinct Sid values found in that export, stored verbatim.
    case MI_FITNESS_APP = '790547985';
    case XIAOMI_SPORTS_APP = 'xiaomisports_app';
    case XIAOMI_HLTH_GEN = 'hlth.gen_93673084683476';
    case XIAOMI_WEAR_APP_MANUALLY = 'xiaomiwear_app_manually';

    public function label(): string
    {
        return match($this) {
            self::DEBUG => 'Debug',
            self::LOCATION => 'Location',
            self::HEALTH_CONNECT => 'Health Connect',
            self::MI_FITNESS_APP => 'Redmi Watch 3 Active',
            self::XIAOMI_SPORTS_APP => 'Mi Fitness App',
            self::XIAOMI_HLTH_GEN => 'Zepp or Mi Band',
            self::XIAOMI_WEAR_APP_MANUALLY => 'Mi App Manually',
        };
    }
}
