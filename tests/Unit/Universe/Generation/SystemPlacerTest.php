<?php

declare(strict_types=1);

namespace App\Tests\Unit\Universe\Generation;

use App\Entity\GlobalPosition;
use App\Universe\Generation\SpiralGalaxyShape;
use App\Universe\Generation\SystemPlacer;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class SystemPlacerTest extends TestCase
{
    private const float MIN_DISTANCE = SystemPlacer::DEFAULT_MIN_DISTANCE;

    public function testPlacesExactlyTheRequestedNumberOfSystems(): void
    {
        self::assertCount(1000, $this->place(1000, seed: 1));
    }

    public function testKeepsMinimalDistanceBetweenEverySystem(): void
    {
        $positions = $this->place(500, seed: 2);
        $closest = INF;

        foreach ($positions as $i => $a) {
            foreach (\array_slice($positions, $i + 1) as $b) {
                $closest = min($closest, $a->distanceTo($b));
            }
        }

        self::assertGreaterThanOrEqual(self::MIN_DISTANCE, $closest);
    }

    public function testSameSeedGivesSameGalaxy(): void
    {
        self::assertEquals($this->place(300, seed: 3), $this->place(300, seed: 3));
        self::assertNotEquals($this->place(300, seed: 3), $this->place(300, seed: 4));
    }

    public function testGeneratesFromCenterOutward(): void
    {
        $farthestSoFar = 0.0;

        foreach ($this->place(800, seed: 5) as $position) {
            $distance = $position->distanceFromCenter();
            // Placement par anneaux de largeur « distance minimale » : jamais en deçà de l'anneau courant
            self::assertGreaterThan($farthestSoFar - self::MIN_DISTANCE, $distance);
            $farthestSoFar = max($farthestSoFar, $distance);
        }
    }

    public function testConcentratesSystemsOnSpiralArms(): void
    {
        $shape = new SpiralGalaxyShape();
        // Hors du bulbe, où la densité est uniforme
        $disk = array_values(array_filter(
            $this->place(1000, seed: 6, shape: $shape),
            static fn(GlobalPosition $p): bool => $p->distanceFromCenter() > 2 * $shape->coreRadius,
        ));
        self::assertGreaterThan(200, \count($disk));

        // La rotation de la galaxie est tirée au hasard : on retient l'orientation qui colle le mieux
        $bestShareOnArms = 0.0;
        for ($step = 0; $step < 180; ++$step) {
            $rotation = $step * 2 * M_PI / 180;
            $onArms = \count(array_filter(
                $disk,
                static fn(GlobalPosition $p): bool => $shape->armProximity($p->distanceFromCenter(), atan2($p->y, $p->x), $rotation) > 0.5,
            ));
            $bestShareOnArms = max($bestShareOnArms, $onArms / \count($disk));
        }

        // Les zones « sur une branche » couvrent environ 37 % du disque : une répartition uniforme y mettrait
        // ~37 % des systèmes (un peu plus en choisissant la meilleure rotation). La forme par défaut en met ~73 %.
        self::assertGreaterThan(0.6, $bestShareOnArms);
    }

    public function testRejectsInvalidRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SystemPlacer())->place(new SpiralGalaxyShape(), 0, self::MIN_DISTANCE, new Randomizer(new Mt19937(1)));
    }

    public function testRejectsNonPositiveMinimalDistance(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SystemPlacer())->place(new SpiralGalaxyShape(), 10, 0.0, new Randomizer(new Mt19937(1)));
    }

    /**
     * @return list<GlobalPosition>
     */
    private function place(int $count, int $seed, ?SpiralGalaxyShape $shape = null): array
    {
        return (new SystemPlacer())->place($shape ?? new SpiralGalaxyShape(), $count, self::MIN_DISTANCE, new Randomizer(new Mt19937($seed)));
    }
}
