<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Combat;

use App\Model\Economy\Resources;
use App\Service\Combat\PillageRules;
use PHPUnit\Framework\TestCase;

final class PillageRulesTest extends TestCase
{
    public function testAttackersTakeHalfOfEachResourceWithinTheirFreeCargo(): void
    {
        $rules = new PillageRules();
        $stock = new Resources(10_000, 6_000, 4_000);

        self::assertEquals(new Resources(5_000, 3_000, 2_000), $rules->loot($stock, 50_000));
        // Cargo libre de 5 000 pour 10 000 voulus : moitié de chaque
        self::assertEquals(new Resources(2_500, 1_500, 1_000), $rules->loot($stock, 5_000));
        self::assertEquals(new Resources(), $rules->loot($stock, 0));
        self::assertEquals(new Resources(), $rules->loot(new Resources(), 1000));
    }

    public function testLootIsSharedInProportionOfFreeCargo(): void
    {
        $shares = new PillageRules()->share(new Resources(3_000, 600, 0), [1 => 2_000.0, 2 => 1_000.0]);

        self::assertEquals(new Resources(2_000, 400, 0), $shares[1]);
        self::assertEquals(new Resources(1_000, 200, 0), $shares[2]);
    }
}
