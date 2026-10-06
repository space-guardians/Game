<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Economy;

use App\Entity\BuildingType;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Repository\BuildingTypeRepository;
use App\Service\Economy\PlanetEconomy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Bilan d'une planète avec les types de bâtiments installés par la migration (§4.2, §4.3).
 */
final class PlanetEconomyTest extends KernelTestCase
{
    use Factories;

    public function testInstallsStartingBuildingTypes(): void
    {
        self::bootKernel();

        $codes = array_map(static fn(BuildingType $type): string => $type->getCode(), $this->types()->findAllOrdered());

        self::assertSame(['metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'solar_plant', 'fusion_reactor', 'metal_storage', 'crystal_storage', 'deuterium_storage', 'robot_factory', 'nanite_factory'], $codes);
    }

    public function testBuildingLevelsDriveProductionAndStorage(): void
    {
        self::bootKernel();
        $planet = EmpireFactory::createOne(['homePlanet' => PlanetFactory::new(['temperature' => 0])])->getHomePlanet();
        $planet->setBuildingLevel($this->type('metal_mine'), 1);
        $planet->setBuildingLevel($this->type('solar_plant'), 1);
        $planet->setBuildingLevel($this->type('metal_storage'), 1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = PlanetFactory::repository()->find($planet->getId());
        \assert(null !== $reloaded);

        $output = self::getContainer()->get(PlanetEconomy::class)->output($reloaded);

        self::assertSame(1, $reloaded->buildingLevel($this->type('metal_mine')));
        self::assertSame(0, $reloaded->buildingLevel($this->type('crystal_mine')));
        self::assertGreaterThan(30 + 33 - 0.001, $output->hourlyProduction->metal);
        self::assertSame(20_000.0, $output->capacity->metal);
        self::assertEqualsWithDelta(22.0 - 11.0, $output->energyBalance(), 1e-9);
    }

    private function type(string $code): BuildingType
    {
        $type = $this->types()->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }

    private function types(): BuildingTypeRepository
    {
        return self::getContainer()->get(BuildingTypeRepository::class);
    }
}
