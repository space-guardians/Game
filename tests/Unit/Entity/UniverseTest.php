<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;
use PHPUnit\Framework\TestCase;

final class UniverseTest extends TestCase
{
    public function testBuildsLogicalAddressOfPlanet(): void
    {
        $system = new StarSystem(new Galaxy(1, 'Voie lactée'), 342, new GlobalPosition(120.5, -80.25));
        $planet = new Planet($system, new OrbitalPosition(7, 70.0, 1.2), 25);

        $address = $planet->getAddress();

        self::assertSame([1, 342, 7], [$address->galaxy, $address->system, $address->position]);
        self::assertSame('1:342:7', (string) $address);
    }

    public function testKeepsBothSidesOfRelationsInSync(): void
    {
        $galaxy = new Galaxy(1, 'Voie lactée');
        $system = new StarSystem($galaxy, 1, new GlobalPosition(0.0, 0.0));
        $planet = new Planet($system, new OrbitalPosition(1, 10.0, 0.0), -40);

        self::assertTrue($galaxy->getSystems()->contains($system));
        self::assertTrue($system->getPlanets()->contains($planet));
    }

    public function testRejectsNonPositiveGalaxyNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Galaxy(0, 'Voie lactée');
    }

    public function testRejectsNonPositiveSystemNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StarSystem(new Galaxy(1, 'Voie lactée'), 0, new GlobalPosition(0.0, 0.0));
    }
}
