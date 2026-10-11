<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Combat;

use App\Model\Economy\Resources;
use App\Service\Combat\DebrisRules;
use PHPUnit\Framework\TestCase;

final class DebrisRulesTest extends TestCase
{
    public function testDestroyedShipsLeaveThirtyPercentOfTheirMetalAndCrystal(): void
    {
        $debris = new DebrisRules()->fromDestroyed([
            ['cost' => new Resources(3000, 1000, 0), 'count' => 5],
            ['cost' => new Resources(20000, 7000, 2000), 'count' => 1],
        ]);

        // (15 000 + 20 000) × 0,3 ; (5 000 + 7 000) × 0,3 ; le deutérium se consume
        self::assertEqualsWithDelta(10_500.0, $debris->metal, 1e-6);
        self::assertEqualsWithDelta(3_600.0, $debris->crystal, 1e-6);
        self::assertSame(0.0, $debris->deuterium);
    }

    public function testRecyclersCollectWithinTheirCapacityInProportion(): void
    {
        $rules = new DebrisRules();
        $field = new Resources(30_000, 10_000);

        self::assertEquals(new Resources(15_000, 5_000), $rules->collectable($field, 20_000));
        self::assertEquals($field, $rules->collectable($field, 100_000));
        self::assertEquals(new Resources(), $rules->collectable($field, 0));
        self::assertEquals(new Resources(), $rules->collectable(new Resources(), 1000));
    }
}
