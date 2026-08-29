<?php

namespace App\Tests\Service\Consolidation;

use App\Service\Consolidation\DataOriginPriority;
use PHPUnit\Framework\TestCase;

class DataOriginPriorityTest extends TestCase
{
    public function testHealthConnectOutranksOtherAppsByDefault(): void
    {
        $priority = new DataOriginPriority();

        self::assertGreaterThan(
            $priority->priorityOf('com.sec.android.app.shealth'),
            $priority->priorityOf('com.android.healthconnect.phone.sensor'),
        );
    }

    public function testPriorityTableIsConfigurable(): void
    {
        // config/services.yaml overrides this for DailyStepsConsolidator —
        // Samsung Health wins for Steps, the opposite of the default above.
        $priority = new DataOriginPriority(['com.sec.android.app.shealth' => 10]);

        self::assertGreaterThan(
            $priority->priorityOf('com.android.healthconnect.phone.sensor'),
            $priority->priorityOf('com.sec.android.app.shealth'),
        );
    }

    public function testUnknownAndNullDataOriginsAreEqualDefaultPriority(): void
    {
        $priority = new DataOriginPriority();

        self::assertSame($priority->priorityOf(null), $priority->priorityOf('com.some.other.app'));
    }
}
