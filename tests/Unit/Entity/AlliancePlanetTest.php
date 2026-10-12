<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Alliance;
use App\Entity\AlliancePlanet;
use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;
use App\Enum\Alliance\AllianceBuildingType;
use App\Model\Economy\Resources;
use PHPUnit\Framework\TestCase;

/**
 * Planète d'alliance (§4.10) : propriété collective, bâtiments uniques montés niveau par niveau, intégrité.
 */
final class AlliancePlanetTest extends TestCase
{
    public function testBuildingsAreRaisedLevelByLevelAndCostDoubles(): void
    {
        $alliancePlanet = $this->alliancePlanet();

        self::assertSame(0, $alliancePlanet->buildingLevel(AllianceBuildingType::Scanner));
        $alliancePlanet->raise(AllianceBuildingType::Scanner);
        $alliancePlanet->raise(AllianceBuildingType::Scanner);

        self::assertSame(2, $alliancePlanet->buildingLevel(AllianceBuildingType::Scanner));
        self::assertSame(0, $alliancePlanet->buildingLevel(AllianceBuildingType::TeleportGate));
        self::assertCount(1, $alliancePlanet->getBuildings());
        self::assertEquals(new Resources(40_000, 80_000, 20_000), AllianceBuildingType::Scanner->cost(2));
    }

    public function testIntegrityNeverDropsBelowZero(): void
    {
        $alliancePlanet = $this->alliancePlanet();

        self::assertSame(75, $alliancePlanet->damage(25));
        self::assertSame(0, $alliancePlanet->damage(500));
    }

    public function testBuiltOnlyOnceTheConstructionIsOver(): void
    {
        $alliancePlanet = $this->alliancePlanet();

        self::assertFalse($alliancePlanet->isBuiltAt(new \DateTimeImmutable('2026-10-12 23:59:59')));
        self::assertTrue($alliancePlanet->isBuiltAt(new \DateTimeImmutable('2026-10-13 00:00:00')));
    }

    public function testAnAlliancePlanetHasNoEmpireAndIsFreedWhenDestroyed(): void
    {
        $planet = $this->alliancePlanet()->getPlanet();
        self::assertTrue($planet->isClaimed());
        self::assertNull($planet->getOwner());

        $planet->abandon(new \DateTimeImmutable('2026-10-14'));

        self::assertFalse($planet->isClaimed());
        self::assertNull($planet->getAlliance());
    }

    public function testAnAlliancePlanetBelongsToASingleAlliance(): void
    {
        $planet = $this->alliancePlanet()->getPlanet();

        $this->expectException(\LogicException::class);

        $planet->assignToAlliance($this->alliance());
    }

    private function alliancePlanet(): AlliancePlanet
    {
        $planet = $this->planet();
        $alliance = $this->alliance();
        $planet->assignToAlliance($alliance);

        return new AlliancePlanet($alliance, $planet, 'Bastion', new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-13'));
    }

    private function planet(): Planet
    {
        $system = new StarSystem(new Galaxy(1, 'Andromède'), 1, new GlobalPosition(0, 0));

        return new Planet($system, new OrbitalPosition(3, 30.0, 0.0), 20);
    }

    private function alliance(): Alliance
    {
        return new Alliance('Ligue', 'LGS', new \DateTimeImmutable('2026-10-01'));
    }
}
