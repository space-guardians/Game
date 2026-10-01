<?php

declare(strict_types=1);

namespace App\Tests\Unit\Universe\Generation;

use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;
use App\Universe\Generation\PlanetGenerator;
use App\Universe\Generation\SystemPlacer;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class PlanetGeneratorTest extends TestCase
{
    public function testGeneratesBetweenThreeAndFifteenPlanetsCoveringTheWholeRange(): void
    {
        $counts = [];
        for ($seed = 1; $seed <= 400; ++$seed) {
            $counts[] = \count($this->populate($seed));
        }

        self::assertSame(PlanetGenerator::MIN_PLANETS, min($counts));
        self::assertSame(PlanetGenerator::MAX_PLANETS, max($counts));
    }

    public function testPlacesPlanetsOnDistinctOrbitsInIncreasingOrder(): void
    {
        for ($seed = 1; $seed <= 50; ++$seed) {
            $orbits = array_map(static fn(Planet $p): int => $p->getPosition()->orbit, $this->populate($seed));

            self::assertSame(array_values(array_unique($orbits)), $orbits);
            self::assertSame($orbits, array_values(array_filter($orbits, static fn(int $o): bool => $o >= 1 && $o <= OrbitalPosition::MAX_ORBIT)));
            $sorted = $orbits;
            sort($sorted);
            self::assertSame($sorted, $orbits);
        }
    }

    public function testOrbitRadiusGrowsWithOrbitAndStaysInsideSystemBounds(): void
    {
        // Deux systèmes voisins ne doivent pas se chevaucher (contrainte de #8)
        self::assertLessThan(SystemPlacer::DEFAULT_MIN_DISTANCE / 2, PlanetGenerator::maxRadius());

        for ($seed = 1; $seed <= 50; ++$seed) {
            $radii = array_map(static fn(Planet $p): float => $p->getPosition()->radius, $this->populate($seed));
            $sorted = $radii;
            sort($sorted);

            self::assertSame($sorted, $radii);
            self::assertLessThanOrEqual(PlanetGenerator::maxRadius(), max($radii));
            self::assertGreaterThan(0.0, min($radii));
        }
    }

    public function testInnerOrbitsAreHotterThanOuterOrbits(): void
    {
        $temperatures = [];
        for ($seed = 1; $seed <= 300; ++$seed) {
            foreach ($this->populate($seed) as $planet) {
                $temperatures[$planet->getPosition()->orbit][] = $planet->getTemperature();
            }
        }
        $average = static fn(int $orbit): float => array_sum($temperatures[$orbit]) / \count($temperatures[$orbit]);

        self::assertGreaterThan($average(5), $average(1));
        self::assertGreaterThan($average(10), $average(5));
        self::assertGreaterThan($average(15), $average(10));
    }

    public function testAttachesPlanetsToTheSystem(): void
    {
        $system = $this->system();
        $planets = (new PlanetGenerator())->populate($system, new Randomizer(new Mt19937(7)));

        self::assertSame($planets, $system->getPlanets()->toArray());
        foreach ($planets as $planet) {
            self::assertSame($system, $planet->getSystem());
        }
    }

    public function testSameSeedGivesSamePlanets(): void
    {
        $describe = static fn(array $planets): array => array_map(
            static fn(Planet $p): array => [$p->getPosition(), $p->getTemperature()],
            $planets,
        );

        self::assertEquals($describe($this->populate(11)), $describe($this->populate(11)));
        self::assertNotEquals($describe($this->populate(11)), $describe($this->populate(12)));
    }

    /**
     * @return list<Planet>
     */
    private function populate(int $seed): array
    {
        return (new PlanetGenerator())->populate($this->system(), new Randomizer(new Mt19937($seed)));
    }

    private function system(): StarSystem
    {
        return new StarSystem(new Galaxy(1, 'Voie des Gardiens'), 1, new GlobalPosition(0.0, 0.0));
    }
}
