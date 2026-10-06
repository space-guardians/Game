<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\Formation;
use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\StarSystem;
use App\Entity\User;
use App\Enum\Account\StartingOrientation;
use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Model\Fleet\FormationCell;
use PHPUnit\Framework\TestCase;

final class FleetTest extends TestCase
{
    public function testAggregatesShipsCargoAndSlowestSpeed(): void
    {
        $fleet = $this->fleet();
        $fighter = $this->type('light_fighter', speed: 12500, cargo: 50);
        $cargo = $this->type('large_cargo', speed: 7500, cargo: 25000);

        self::assertSame(0, $fleet->slowestSpeed());
        $fleet->addShips($fighter, 10);
        $fleet->addShips($cargo, 2);
        $fleet->addShips($fighter, 5);

        self::assertCount(2, $fleet->getShips());
        self::assertSame(17, $fleet->shipCount());
        self::assertSame(15, $fleet->shipCount($fighter));
        self::assertSame(15 * 50 + 2 * 25000, $fleet->cargo());
        self::assertSame(7500, $fleet->slowestSpeed());
    }

    public function testRemovingShipsAlsoEmptiesFormationFromTheBack(): void
    {
        $fleet = $this->fleet();
        $colony = $this->type('colony_ship');
        $fleet->addShips($colony, 3);
        $formation = new Formation($fleet);
        $formation->arrange([
            new FormationCell(FormationRow::Front, FormationColumn::Center, 'colony_ship', 1),
            new FormationCell(FormationRow::Back, FormationColumn::Left, 'colony_ship', 2),
        ], ['colony_ship' => $colony]);
        $fleet->setFormation($formation);

        $fleet->removeShips($colony, 2);

        self::assertSame(1, $fleet->shipCount($colony));
        self::assertSame(0, $formation->total(FormationRow::Back, FormationColumn::Left));
        self::assertSame(1, $formation->total(FormationRow::Front, FormationColumn::Center));

        $fleet->removeShips($colony, 1);
        self::assertTrue($fleet->isEmpty());
        self::assertCount(0, $formation->getSlots());
    }

    public function testCannotRemoveMoreShipsThanTheFleetHas(): void
    {
        $fleet = $this->fleet();
        $fleet->addShips($this->type('colony_ship'), 1);

        $this->expectException(\InvalidArgumentException::class);

        $fleet->removeShips($this->type('light_fighter'), 1);
    }

    public function testNameIsTrimmed(): void
    {
        self::assertSame('Escadre Orion', $this->fleet('  Escadre Orion ')->getName());
    }

    public function testCannotAddNoShip(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fleet()->addShips($this->type('light_fighter'), 0);
    }

    public function testPlanetCannotGiveMoreShipsThanItHas(): void
    {
        $planet = $this->planet();
        $fighter = $this->type('light_fighter');
        $planet->addShips($fighter, 3);
        $planet->removeShips($fighter, 2);
        self::assertSame(1, $planet->shipCount($fighter));

        $this->expectException(\InvalidArgumentException::class);

        $planet->removeShips($fighter, 2);
    }

    private function fleet(string $name = 'Flotte 1'): Fleet
    {
        $planet = $this->planet();
        $empire = new Empire(new User('orion@exemple.fr', new \DateTimeImmutable()), 'Orion', StartingOrientation::Aggressive, $planet, new \DateTimeImmutable());

        return new Fleet($empire, $name, $planet, new \DateTimeImmutable());
    }

    private function planet(): Planet
    {
        return new Planet(new StarSystem(new Galaxy(1, 'Orion'), 1, new GlobalPosition(100.0, 0.0)), new OrbitalPosition(4, 40.0, 0.0), 20);
    }

    private function type(string $code, int $speed = 10000, int $cargo = 0): ShipType
    {
        $type = new ShipType($code, ucfirst($code));
        $type->setSpeed($speed);
        $type->setCargo($cargo);

        return $type;
    }
}
