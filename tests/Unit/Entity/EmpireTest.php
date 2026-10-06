<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Empire;
use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\Account\StartingOrientation;
use PHPUnit\Framework\TestCase;

final class EmpireTest extends TestCase
{
    public function testTakesHomePlanetAsActivePlanet(): void
    {
        $planet = $this->planet();

        $empire = $this->empire($planet, '  Ordre   d’Orion ');

        self::assertSame('Ordre d’Orion', $empire->getName());
        self::assertSame($planet, $empire->getHomePlanet());
        self::assertSame($planet, $empire->getActivePlanet());
        self::assertSame($empire, $planet->getOwner());
        self::assertSame(0, $empire->getScore());
    }

    public function testCannotStartOnAnotherEmpiresPlanet(): void
    {
        $planet = $this->planet();
        $this->empire($planet, 'Premier');

        $this->expectException(\LogicException::class);

        $this->empire($planet, 'Second');
    }

    public function testSwitchesOnlyToOwnPlanets(): void
    {
        $empire = $this->empire($this->planet(), 'Orion');
        $colony = $this->planet();
        $colony->assignTo($empire);

        $empire->switchTo($colony);
        self::assertSame($colony, $empire->getActivePlanet());
        self::assertFalse($empire->isHomePlanet($colony));

        $this->expectException(\DomainException::class);
        $empire->switchTo($this->planet());
    }

    public function testResearchLevelsBelongToTheEmpire(): void
    {
        $empire = $this->empire($this->planet(), 'Orion');
        $energy = new Technology('energy', 'Énergie');

        self::assertSame(0, $empire->researchLevel($energy));
        $empire->setResearchLevel($energy, 2);
        $empire->setResearchLevel($energy, 3);

        self::assertSame(3, $empire->researchLevel($energy));
        self::assertCount(1, $empire->getResearches());
        self::assertSame(0, $empire->researchLevel(new Technology('computer', 'Informatique')));
    }

    public function testResearchLevelCannotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->empire($this->planet(), 'Orion')->setResearchLevel(new Technology('energy', 'Énergie'), -1);
    }

    private function empire(Planet $planet, string $name): Empire
    {
        return new Empire(new User($name . '@exemple.fr', new \DateTimeImmutable()), $name, StartingOrientation::Aggressive, $planet, new \DateTimeImmutable());
    }

    private function planet(): Planet
    {
        $system = new StarSystem(new Galaxy(1, 'Orion'), 1, new GlobalPosition(100.0, 0.0));

        return new Planet($system, new OrbitalPosition(4, 40.0, 0.0), 20);
    }
}
