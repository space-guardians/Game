<?php

declare(strict_types=1);

namespace App\Tests\Unit\Universe\Generation;

use App\Entity\Galaxy;
use App\Entity\StarSystem;
use App\Universe\Generation\GalaxyGenerator;
use App\Universe\Generation\PlanetGenerator;
use App\Universe\Generation\SpiralGalaxyShape;
use App\Universe\Generation\SystemPlacer;
use PHPUnit\Framework\TestCase;

final class GalaxyGeneratorTest extends TestCase
{
    public function testBuildsGalaxyWithRequestedSystemsAndTheirPlanets(): void
    {
        $galaxy = $this->generate(seed: 1, systems: 120);

        self::assertSame(3, $galaxy->getNumber());
        self::assertSame('Galaxie de test', $galaxy->getName());
        self::assertCount(120, $galaxy->getSystems());
        foreach ($galaxy->getSystems() as $system) {
            self::assertGreaterThanOrEqual(PlanetGenerator::MIN_PLANETS, $system->getPlanets()->count());
            self::assertLessThanOrEqual(PlanetGenerator::MAX_PLANETS, $system->getPlanets()->count());
        }
    }

    public function testNumbersSystemsFromCenterOutward(): void
    {
        $systems = $this->generate(seed: 2, systems: 200)->getSystems()->toArray();

        self::assertSame(range(1, 200), array_map(static fn(StarSystem $s): int => $s->getNumber(), $systems));
        self::assertLessThan(
            $systems[199]->getPosition()->distanceFromCenter(),
            $systems[0]->getPosition()->distanceFromCenter(),
        );
    }

    public function testSameSeedReproducesTheSameGalaxy(): void
    {
        self::assertEquals($this->describe($this->generate(seed: 3)), $this->describe($this->generate(seed: 3)));
        self::assertNotEquals($this->describe($this->generate(seed: 3)), $this->describe($this->generate(seed: 4)));
    }

    public function testAppliesShape(): void
    {
        $twoArms = $this->generate(seed: 5, shape: new SpiralGalaxyShape(arms: 2));
        $sixArms = $this->generate(seed: 5, shape: new SpiralGalaxyShape(arms: 6));

        self::assertNotEquals($this->describe($twoArms), $this->describe($sixArms));
    }

    private function generate(int $seed, int $systems = 60, ?SpiralGalaxyShape $shape = null): Galaxy
    {
        return (new GalaxyGenerator(new SystemPlacer(), new PlanetGenerator()))
            ->generate(3, 'Galaxie de test', $seed, $shape ?? new SpiralGalaxyShape(), $systems);
    }

    /**
     * Description comparable de la galaxie : position des systèmes, orbite et température des planètes.
     *
     * @return list<array{float, float, list<array{int, int}>}>
     */
    private function describe(Galaxy $galaxy): array
    {
        $description = [];
        foreach ($galaxy->getSystems() as $system) {
            $planets = [];
            foreach ($system->getPlanets() as $planet) {
                $planets[] = [$planet->getPosition()->orbit, $planet->getTemperature()];
            }
            $description[] = [$system->getPosition()->x, $system->getPosition()->y, $planets];
        }

        return $description;
    }
}
