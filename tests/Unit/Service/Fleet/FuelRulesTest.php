<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Fleet;

use App\Service\Fleet\FuelRules;
use PHPUnit\Framework\TestCase;

final class FuelRulesTest extends TestCase
{
    public function testConsumptionGrowsWithDistanceShipsDriveAndSpeed(): void
    {
        $rules = new FuelRules();
        $fighters = [['consumption' => 20, 'quantity' => 10, 'drive' => 'combustion_drive']];

        // 20 × 10 × 1 × 35 000 / 35 000 × (1 + 1)² = 800
        self::assertSame(800.0, $rules->consumption($fighters, 35_000, 100));
        // Moitié de la distance : moitié du carburant
        self::assertSame(400.0, $rules->consumption($fighters, 17_500, 100));
        // 50 % de vitesse : (1,5)² au lieu de 2²
        self::assertSame(450.0, $rules->consumption($fighters, 35_000, 50));
        // Propulsion à impulsion : × 1,5 ; plusieurs types s'additionnent
        self::assertSame(800.0 + 1200.0, $rules->consumption([
            ...$fighters,
            ['consumption' => 20, 'quantity' => 10, 'drive' => 'impulse_drive'],
        ], 35_000, 100));
        self::assertSame(0.0, $rules->consumption($fighters, 0, 100));
        // Arrondi à l'unité supérieure
        self::assertSame(1.0, $rules->consumption([['consumption' => 1, 'quantity' => 1, 'drive' => null]], 10, 10));
    }

    public function testReachIsTheShareOfTheTripTheFuelCovers(): void
    {
        $rules = new FuelRules();

        self::assertSame(1.0, $rules->reach(1000, 800));
        self::assertSame(0.25, $rules->reach(200, 800));
        self::assertSame(0.0, $rules->reach(0, 800));
        self::assertSame(1.0, $rules->reach(0, 0));
    }
}
