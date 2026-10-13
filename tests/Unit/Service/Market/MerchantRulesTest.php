<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Market;

use App\Entity\GlobalPosition;
use App\Service\Market\MerchantRules;
use App\Service\Universe\PlanetGenerator;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Placement des marchands (§4.15) : nombre selon la taille de la galaxie, répartition la plus espacée possible,
 * station en deçà de la première orbite.
 */
final class MerchantRulesTest extends TestCase
{
    public function testOneMerchantPerTenSystemsAndAtLeastOne(): void
    {
        $rules = new MerchantRules();

        self::assertSame([0, 1, 1, 2, 100], [$rules->count(0), $rules->count(3), $rules->count(14), $rules->count(15), $rules->count(1000)]);
    }

    public function testEachMerchantGoesToTheSystemFarthestFromThePreviousOnes(): void
    {
        // Une ligne de systèmes de 0 à 100 : après le premier tiré, le suivant est à l'autre bout, puis au milieu
        $systems = array_map(static fn(int $x): GlobalPosition => new GlobalPosition((float) $x, 0.0), range(0, 100, 10));
        $rules = new MerchantRules();

        $chosen = $rules->pick($systems, 3, new Randomizer(new Mt19937(1)));

        self::assertCount(3, array_unique($chosen));
        $xs = array_map(static fn(int $index): float => $systems[$index]->x, $chosen);
        self::assertSame($xs[0] >= 50.0 ? 0.0 : 100.0, $xs[1]);
        // Le troisième est, de tous les systèmes, le plus éloigné du marchand le plus proche
        $gap = static fn(float $x): float => min(abs($x - $xs[0]), abs($x - $xs[1]));
        self::assertSame(max(array_map(static fn(GlobalPosition $system): float => $gap($system->x), $systems)), $gap($xs[2]));
        // Jamais plus de marchands que de systèmes
        self::assertCount(11, $rules->pick($systems, 50, new Randomizer(new Mt19937(1))));
        self::assertSame([], $rules->pick([], 3, new Randomizer(new Mt19937(1))));
    }

    public function testStationLiesInsideTheFirstOrbit(): void
    {
        $randomizer = new Randomizer(new Mt19937(7));
        foreach (range(1, 20) as $ignored) {
            [$x, $y] = new MerchantRules()->station($randomizer);
            self::assertEqualsWithDelta(MerchantRules::STATION_RADIUS, hypot($x, $y), 0.01);
        }
        self::assertLessThan(PlanetGenerator::FIRST_ORBIT_RADIUS, MerchantRules::STATION_RADIUS);
    }
}
