<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\StarSystem;
use PHPUnit\Framework\TestCase;

final class PlanetShipsTest extends TestCase
{
    public function testShipsAddUpPerType(): void
    {
        $planet = new Planet(new StarSystem(new Galaxy(1, 'Orion'), 1, new GlobalPosition(100.0, 0.0)), new OrbitalPosition(4, 40.0, 0.0), 20);
        $fighter = new ShipType('light_fighter', 'Chasseur léger');

        self::assertSame(0, $planet->shipCount($fighter));
        $planet->addShips($fighter, 3);
        $planet->addShips($fighter, 2);

        self::assertSame(5, $planet->shipCount($fighter));
        self::assertCount(1, $planet->getShips());
        self::assertSame(0, $planet->shipCount(new ShipType('cruiser', 'Croiseur')));
    }

    public function testCannotAddNegativeShips(): void
    {
        $planet = new Planet(new StarSystem(new Galaxy(1, 'Orion'), 1, new GlobalPosition(100.0, 0.0)), new OrbitalPosition(4, 40.0, 0.0), 20);

        $this->expectException(\InvalidArgumentException::class);

        $planet->addShips(new ShipType('light_fighter', 'Chasseur léger'), -1);
    }
}
