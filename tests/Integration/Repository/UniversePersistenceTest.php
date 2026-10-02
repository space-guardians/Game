<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Factory\GalaxyFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Repository\PlanetRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class UniversePersistenceTest extends KernelTestCase
{
    use Factories;

    public function testPersistsPlanetWithPositionsAndAddress(): void
    {
        $galaxy = GalaxyFactory::createOne(['number' => 2]);
        $system = StarSystemFactory::createOne([
            'galaxy' => $galaxy,
            'number' => 342,
            'position' => new GlobalPosition(-1234.5, 678.25),
        ]);
        $id = PlanetFactory::createOne([
            'system' => $system,
            'position' => new OrbitalPosition(7, 70.5, 1.25),
            'temperature' => -30,
        ])->getId();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $planet = self::getContainer()->get(PlanetRepository::class)->find($id);

        self::assertInstanceOf(Planet::class, $planet);
        self::assertSame('2:342:7', (string) $planet->getAddress());
        self::assertSame(70.5, $planet->getPosition()->radius);
        self::assertSame(1.25, $planet->getPosition()->angle);
        self::assertSame(-30, $planet->getTemperature());
        self::assertSame(-1234.5, $planet->getSystem()->getPosition()->x);
        self::assertSame(678.25, $planet->getSystem()->getPosition()->y);
    }

    /** Non-régression : avec precision=14, PHP tronquait les flottants envoyés à PostgreSQL */
    public function testStoresCoordinatesWithoutPrecisionLoss(): void
    {
        $x = -68.578319280034123;
        $y = 1234.5678901234567;
        $id = StarSystemFactory::createOne(['position' => new GlobalPosition($x, $y)])->getId();

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $system = StarSystemFactory::repository()->find($id);
        self::assertNotNull($system);

        self::assertSame($x, $system->getPosition()->x);
        self::assertSame($y, $system->getPosition()->y);
    }

    public function testAllowsSameSystemNumberInDifferentGalaxies(): void
    {
        StarSystemFactory::createOne(['number' => 1]);
        StarSystemFactory::createOne(['number' => 1]);

        self::assertSame(2, StarSystemFactory::repository()->count(['number' => 1]));
    }

    public function testRejectsDuplicateSystemNumberInGalaxy(): void
    {
        $galaxy = GalaxyFactory::createOne();
        StarSystemFactory::createOne(['galaxy' => $galaxy, 'number' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        StarSystemFactory::createOne(['galaxy' => $galaxy, 'number' => 1]);
    }

    public function testRejectsTwoPlanetsOnSameOrbitOfSystem(): void
    {
        $system = StarSystemFactory::createOne();
        PlanetFactory::createOne(['system' => $system, 'position' => new OrbitalPosition(3, 30.0, 0.0)]);

        $this->expectException(UniqueConstraintViolationException::class);

        PlanetFactory::createOne(['system' => $system, 'position' => new OrbitalPosition(3, 30.0, 1.0)]);
    }
}
