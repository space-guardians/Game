<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Universe;

use App\Enum\Account\StartingOrientation;
use App\Model\Universe\PlanetCandidate;
use App\Service\Universe\StartingPlanetSelector;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class StartingPlanetSelectorTest extends TestCase
{
    public function testNoCandidateMeansNoPlanet(): void
    {
        self::assertNull($this->selector()->select([], StartingOrientation::Aggressive));
    }

    public function testAggressiveStartsNearCenterAndProducerOnTheOutskirts(): void
    {
        // 20 systèmes de 3 planètes, aux distances 1 000, 2 000… 20 000
        $candidates = $this->galaxy(20, 3);
        $innerLimit = 7_000.0;
        $outerLimit = 14_000.0;

        for ($seed = 1; $seed <= 50; ++$seed) {
            $aggressive = $this->selector($seed)->select($candidates, StartingOrientation::Aggressive);
            $producer = $this->selector($seed)->select($candidates, StartingOrientation::Producer);
            self::assertNotNull($aggressive);
            self::assertNotNull($producer);
            self::assertLessThanOrEqual($innerLimit, $aggressive->distance);
            self::assertGreaterThanOrEqual($outerLimit, $producer->distance);
        }
    }

    public function testSpreadsPlayersOverLeastOccupiedSystems(): void
    {
        // Tous les systèmes comptent déjà un joueur, sauf le n°3 (dans la zone centrale)
        $candidates = array_map(
            static fn(PlanetCandidate $candidate): PlanetCandidate => new PlanetCandidate(
                $candidate->planetId,
                $candidate->systemId,
                $candidate->distance,
                3 === $candidate->systemId ? 0 : 1,
            ),
            $this->galaxy(20, 3),
        );

        for ($seed = 1; $seed <= 20; ++$seed) {
            self::assertSame(3, $this->selector($seed)->select($candidates, StartingOrientation::Aggressive)?->systemId);
        }
    }

    public function testZoneFollowsRemainingFreePlanets(): void
    {
        // Le centre est plein : seuls restent des systèmes lointains, la zone agressive se décale vers eux
        $candidates = [
            new PlanetCandidate(1, 1, 15_000.0, 0),
            new PlanetCandidate(2, 2, 18_000.0, 0),
            new PlanetCandidate(3, 3, 20_000.0, 0),
        ];

        self::assertSame(1, $this->selector()->select($candidates, StartingOrientation::Aggressive)?->planetId);
        self::assertSame(3, $this->selector()->select($candidates, StartingOrientation::Producer)?->planetId);
    }

    public function testSameSeedGivesSamePlanet(): void
    {
        $candidates = $this->galaxy(20, 3);

        self::assertSame(
            $this->selector(42)->select($candidates, StartingOrientation::Producer)?->planetId,
            $this->selector(42)->select($candidates, StartingOrientation::Producer)?->planetId,
        );
    }

    private function selector(int $seed = 1): StartingPlanetSelector
    {
        return new StartingPlanetSelector(new Randomizer(new Mt19937($seed)));
    }

    /** @return list<PlanetCandidate> */
    private function galaxy(int $systems, int $planetsPerSystem): array
    {
        $candidates = [];
        for ($system = 1; $system <= $systems; ++$system) {
            for ($planet = 1; $planet <= $planetsPerSystem; ++$planet) {
                $candidates[] = new PlanetCandidate($system * 100 + $planet, $system, $system * 1_000.0, 0);
            }
        }

        return $candidates;
    }
}
