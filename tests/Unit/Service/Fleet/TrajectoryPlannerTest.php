<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Fleet;

use App\Entity\GlobalPosition;
use App\Enum\Fleet\SegmentKind;
use App\Model\Fleet\SpacePosition;
use App\Model\Fleet\Trajectory;
use App\Service\Fleet\TrajectoryPlanner;
use App\Service\Universe\PlanetGenerator;
use App\Service\Universe\SystemPlacer;
use PHPUnit\Framework\TestCase;

final class TrajectoryPlannerTest extends TestCase
{
    private const int GALAXY = 1;

    public function testSystemEdgeLiesBetweenLastOrbitAndNeighbourSystems(): void
    {
        self::assertGreaterThan(PlanetGenerator::maxRadius(), TrajectoryPlanner::SYSTEM_RADIUS);
        self::assertLessThan(SystemPlacer::DEFAULT_MIN_DISTANCE / 2, TrajectoryPlanner::SYSTEM_RADIUS);
    }

    public function testSameSystemIsASingleLocalSegment(): void
    {
        $trajectory = $this->plan($this->planet(1, 0, 0, 10, 0), $this->planet(1, 0, 0, 0, -30));

        self::assertSame([SegmentKind::Local], $this->kinds($trajectory));
        self::assertEqualsWithDelta(sqrt(10 ** 2 + 30 ** 2), $trajectory->distance, 1e-9);
    }

    public function testPlanetToPlanetInAnotherSystemHasThreeSegments(): void
    {
        // Système d'origine en (0 ; 0), cible en (1 000 ; 0) ; lisières à 96
        $trajectory = $this->plan($this->planet(1, 0, 0, 10, 0), $this->planet(2, 1000, 0, -20, 0));

        self::assertSame([SegmentKind::Exit, SegmentKind::Interstellar, SegmentKind::Approach], $this->kinds($trajectory));
        self::assertSame([86.0, 808.0, 76.0], array_map(static fn($segment): float => round($segment->distance, 9), $trajectory->segments));
        self::assertEquals(new GlobalPosition(96, 0), $trajectory->segments[0]->to);
        self::assertEquals(new GlobalPosition(904, 0), $trajectory->segments[1]->to);
        self::assertEquals(new GlobalPosition(980, 0), $trajectory->segments[2]->to);
        self::assertEqualsWithDelta(970.0, $trajectory->distance, 1e-9);
    }

    public function testWholeSystemTargetStopsAtItsEdge(): void
    {
        $trajectory = $this->plan($this->planet(1, 0, 0, 10, 0), SpacePosition::of(self::GALAXY, 2, new GlobalPosition(1000, 0)));

        self::assertSame([SegmentKind::Exit, SegmentKind::Interstellar], $this->kinds($trajectory));
        self::assertEquals(new GlobalPosition(904, 0), $trajectory->segments[1]->to);
    }

    public function testFleetAtSystemLevelLeavesFromItsEdge(): void
    {
        $trajectory = $this->plan(SpacePosition::of(self::GALAXY, 1, new GlobalPosition(0, 0)), $this->planet(2, 1000, 0, -20, 0));

        self::assertSame([SegmentKind::Interstellar, SegmentKind::Approach], $this->kinds($trajectory));
        self::assertEquals(new GlobalPosition(96, 0), $trajectory->segments[0]->from);
    }

    public function testDeepSpaceTargetAndOrigin(): void
    {
        $deepSpace = SpacePosition::of(self::GALAXY, null, new GlobalPosition(0, 500));

        $outbound = $this->plan($this->planet(1, 0, 0, 0, 10), $deepSpace);
        self::assertSame([SegmentKind::Exit, SegmentKind::Interstellar], $this->kinds($outbound));
        self::assertEqualsWithDelta(500.0 - 10.0, $outbound->distance, 1e-9);

        $inbound = $this->plan($deepSpace, $this->planet(1, 0, 0, 0, 10));
        self::assertSame([SegmentKind::Interstellar, SegmentKind::Approach], $this->kinds($inbound));
        self::assertEqualsWithDelta(490.0, $inbound->distance, 1e-9);
    }

    public function testPointInsideASystemIsReachedLikeAPlanet(): void
    {
        $trajectory = $this->plan($this->planet(1, 0, 0, 10, 0), SpacePosition::of(self::GALAXY, 1, new GlobalPosition(0, 0), 50.0, 0.0));

        self::assertSame([SegmentKind::Local], $this->kinds($trajectory));
        self::assertEqualsWithDelta(40.0, $trajectory->distance, 1e-9);
    }

    public function testStayingInPlaceHasNoSegment(): void
    {
        $here = $this->planet(1, 0, 0, 10, 0);

        $trajectory = $this->plan($here, $here);

        self::assertSame([], $trajectory->segments);
        self::assertEquals($here->global(), $trajectory->pointAt(0.5)['position']);
    }

    public function testPositionAlongTheTrajectory(): void
    {
        $trajectory = $this->plan($this->planet(1, 0, 0, 10, 0), $this->planet(2, 1000, 0, -20, 0));

        self::assertEquals(new GlobalPosition(10, 0), $trajectory->pointAt(0.0)['position']);
        // 485 sur 970 : 86 de sortie, puis 399 de transit depuis la lisière (96 ; 0)
        $middle = $trajectory->pointAt(0.5);
        self::assertEqualsWithDelta(495.0, $middle['position']->x, 1e-9);
        self::assertSame(SegmentKind::Interstellar, $middle['segment']?->kind);
        self::assertEquals(new GlobalPosition(980, 0), $trajectory->pointAt(1.0)['position']);
    }

    public function testTravelStaysInOneGalaxy(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->plan($this->planet(1, 0, 0, 10, 0), SpacePosition::of(2, null, new GlobalPosition(0, 0)));
    }

    public function testLocalPointNeedsASystem(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SpacePosition::of(self::GALAXY, null, new GlobalPosition(0, 0), 1.0, 1.0);
    }

    private function planet(int $system, float $systemX, float $systemY, float $localX, float $localY): SpacePosition
    {
        return SpacePosition::of(self::GALAXY, $system, new GlobalPosition($systemX, $systemY), $localX, $localY, $system * 100);
    }

    private function plan(SpacePosition $from, SpacePosition $to): Trajectory
    {
        return new TrajectoryPlanner()->plan($from, $to);
    }

    /** @return list<SegmentKind> */
    private function kinds(Trajectory $trajectory): array
    {
        return array_map(static fn($segment): SegmentKind => $segment->kind, $trajectory->segments);
    }
}
