<?php

namespace App\Tests\Enum;

use App\Enum\RecordType;
use PHPUnit\Framework\TestCase;

class RecordTypeTest extends TestCase
{
    public function testCanonicalizeStripsLegacyRecordSuffix(): void
    {
        self::assertSame('Steps', RecordType::canonicalize('StepsRecord'));
        self::assertSame('Steps', RecordType::canonicalize('Steps'));
    }

    public function testCanonicalizeLeavesUnrelatedTypesUnchanged(): void
    {
        self::assertSame('LocationFix', RecordType::canonicalize('LocationFix'));
        self::assertSame('dynamic', RecordType::canonicalize('dynamic'));
    }

    public function testVariantsIncludesBothFormsForAffectedTypes(): void
    {
        self::assertEqualsCanonicalizing(['Steps', 'StepsRecord'], RecordType::variants('StepsRecord'));
        self::assertEqualsCanonicalizing(['Steps', 'StepsRecord'], RecordType::variants('Steps'));
    }

    public function testVariantsIsJustTheTypeItselfForUnaffectedTypes(): void
    {
        self::assertSame(['LocationFix'], RecordType::variants('LocationFix'));
    }
}
