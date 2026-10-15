<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pve;

use App\Service\Pve\BossFleetRules;
use PHPUnit\Framework\TestCase;

/**
 * Flotte d'un « boss » (§4.14) : cible = flotte militaire moyenne du top 10 × force, composition mise à l'échelle.
 */
final class BossFleetRulesTest extends TestCase
{
    public function testTargetIsTheTopAverageTimesStrength(): void
    {
        $rules = new BossFleetRules();

        self::assertSame(300.0, $rules->target([100, 200, 300], 150));
        self::assertSame(0.0, $rules->target([], 150));
    }

    public function testCompositionScalesTheBaseToTheTarget(): void
    {
        $rules = new BossFleetRules();
        $base = ['light_fighter' => 150, 'cruiser' => 60];
        $points = ['light_fighter' => 4.0, 'cruiser' => 29.0];

        // Base : 150 × 4 + 60 × 29 = 2 340 points ; deux fois plus
        self::assertSame(['light_fighter' => 300, 'cruiser' => 120], $rules->composition($base, $points, 4680.0));
        // Jamais moins d'un vaisseau par type, même sans joueurs classés
        self::assertSame(['light_fighter' => 1, 'cruiser' => 1], $rules->composition($base, $points, 0.0));
    }
}
