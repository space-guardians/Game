<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Alliance;

use App\Entity\GlobalPosition;
use App\Enum\Fleet\SegmentKind;
use App\Model\Fleet\SpacePosition;
use App\Model\Fleet\Trajectory;
use App\Model\Fleet\TrajectorySegment;
use App\Service\Alliance\ScannerRules;
use PHPUnit\Framework\TestCase;

/**
 * Règles du scanner d'alliance (§4.10) : portée, plus courte distance d'un trajet, avancement d'une flotte en vol.
 */
final class ScannerRulesTest extends TestCase
{
    private ScannerRules $rules;

    protected function setUp(): void
    {
        $this->rules = new ScannerRules();
    }

    public function testRangeGrowsWithTheLevel(): void
    {
        self::assertSame(0.0, $this->rules->range(0));
        self::assertSame(ScannerRules::RANGE_PER_LEVEL, $this->rules->range(1));
        self::assertSame(3 * ScannerRules::RANGE_PER_LEVEL, $this->rules->range(3));
    }

    public function testClosestApproachMeasuresTheWholeTrajectory(): void
    {
        $trajectory = $this->trajectory(new GlobalPosition(0, 0), new GlobalPosition(1000, 0), new GlobalPosition(1000, 1000));

        // Passe à 300 du point au milieu du premier segment, sans s'arrêter près de lui
        self::assertEqualsWithDelta(300.0, $this->rules->closestApproach($trajectory, new GlobalPosition(500, -300)), 1e-9);
        // Au-delà d'une extrémité : distance au coin
        self::assertEqualsWithDelta(500.0, $this->rules->closestApproach($trajectory, new GlobalPosition(1300, -400)), 1e-9);
        self::assertEqualsWithDelta(100.0, $this->rules->closestApproach($trajectory, new GlobalPosition(1100, 600)), 1e-9);
    }

    public function testTravelledFractionStopsWhereTheFuelRunsOut(): void
    {
        $departed = new \DateTimeImmutable('2026-10-12 10:00:00');
        $arrives = new \DateTimeImmutable('2026-10-12 11:00:00');

        self::assertSame(0.0, $this->rules->travelled($departed, $arrives, 1.0, new \DateTimeImmutable('2026-10-12 09:00:00')));
        self::assertSame(0.5, $this->rules->travelled($departed, $arrives, 1.0, new \DateTimeImmutable('2026-10-12 10:30:00')));
        // Panne aux deux tiers : à l'heure de la panne, la flotte n'a fait que les deux tiers du trajet
        self::assertEqualsWithDelta(2 / 3, $this->rules->travelled($departed, $arrives, 2 / 3, new \DateTimeImmutable('2026-10-12 12:00:00')), 1e-9);
    }

    private function trajectory(GlobalPosition ...$points): Trajectory
    {
        $segments = [];
        for ($i = 1; $i < \count($points); ++$i) {
            $segments[] = new TrajectorySegment(SegmentKind::Interstellar, $points[$i - 1], $points[$i]);
        }

        return new Trajectory(SpacePosition::deepSpace(1, $points[0]), SpacePosition::deepSpace(1, $points[array_key_last($points)]), $segments);
    }
}
