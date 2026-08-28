<?php

namespace App\Tests\Service\Consolidation;

use App\Service\Consolidation\DataOriginPriority;
use PHPUnit\Framework\TestCase;

class DataOriginPriorityTest extends TestCase
{
    public function testHealthConnectOutranksOtherApps(): void
    {
        $priority = new DataOriginPriority();

        self::assertGreaterThan(
            $priority->priorityOf('com.sec.android.app.shealth'),
            $priority->priorityOf('com.android.healthconnect.phone.sensor'),
        );
    }

    public function testUnknownAndNullDataOriginsAreEqualDefaultPriority(): void
    {
        $priority = new DataOriginPriority();

        self::assertSame($priority->priorityOf(null), $priority->priorityOf('com.some.other.app'));
    }
}
