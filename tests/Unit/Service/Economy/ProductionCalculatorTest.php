<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Economy;

use App\Entity\BuildingType;
use App\Model\Economy\EconomySettings;
use App\Model\Economy\ResourceRates;
use App\Model\Economy\Resources;
use App\Service\Economy\BuildingRules;
use App\Service\Economy\ProductionCalculator;
use PHPUnit\Framework\TestCase;

final class ProductionCalculatorTest extends TestCase
{
    private ProductionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProductionCalculator(new BuildingRules());
    }

    public function testNewPlanetHasBaseProductionAndBaseStorage(): void
    {
        $output = $this->calculator->compute($this->levels([]), 20, 4, $this->settings());

        self::assertEquals(new ResourceRates(30, 15, 0), $output->hourlyProduction);
        self::assertEquals(new Resources(10_000, 10_000, 10_000), $output->capacity);
        self::assertSame(0.0, $output->energyBalance());
        self::assertSame(1.0, $output->productionFactor);
    }

    public function testPoweredMinesAddToBaseProduction(): void
    {
        $output = $this->calculator->compute($this->levels(['metal_mine' => 1, 'solar_plant' => 1]), 0, 4, $this->settings());

        // Mine niv. 1 : 33 métal/h pour 11 d'énergie ; centrale solaire niv. 1 : 22 d'énergie à 0 °C
        self::assertEqualsWithDelta(30 + 33, $output->hourlyProduction->metal, 1e-9);
        self::assertEqualsWithDelta(22.0, $output->energyProduced, 1e-9);
        self::assertEqualsWithDelta(11.0, $output->energyConsumed, 1e-9);
        self::assertSame(1.0, $output->productionFactor);
    }

    public function testEnergyDeficitReducesMinesButNotBaseProduction(): void
    {
        $output = $this->calculator->compute($this->levels(['metal_mine' => 2]), 0, 4, $this->settings());

        // Aucune centrale : les mines ne produisent rien, la production naturelle reste
        self::assertSame(0.0, $output->productionFactor);
        self::assertEquals(new ResourceRates(30, 15, 0), $output->hourlyProduction);
        self::assertLessThan(0, $output->energyBalance());
    }

    public function testPartialEnergyGivesProportionalProduction(): void
    {
        $output = $this->calculator->compute($this->levels(['metal_mine' => 1, 'crystal_mine' => 1, 'solar_plant' => 1]), 0, 4, $this->settings());

        // 22 d'énergie pour 22 consommés : juste assez
        self::assertEqualsWithDelta(1.0, $output->productionFactor, 1e-9);

        $short = $this->calculator->compute($this->levels(['metal_mine' => 2, 'crystal_mine' => 1, 'solar_plant' => 1]), 0, 4, $this->settings());
        self::assertEqualsWithDelta(22 / (2 * 10 * 1.1 ** 2 + 11), $short->productionFactor, 1e-9);
    }

    public function testFusionConsumesDeuterium(): void
    {
        $output = $this->calculator->compute($this->levels(['fusion_reactor' => 2]), 20, 4, $this->settings());

        self::assertEqualsWithDelta(30 * 2 * 1.05 ** 2, $output->energyProduced, 1e-9);
        self::assertEqualsWithDelta(-(10 * 2 * 1.1 ** 2), $output->hourlyProduction->deuterium, 1e-9);
    }

    public function testOrbitBonusAppliesToMinesOnly(): void
    {
        $levels = $this->levels(['metal_mine' => 1, 'solar_plant' => 5]);

        $central = $this->calculator->compute($levels, 0, 8, $this->settings());
        $outer = $this->calculator->compute($levels, 0, 15, $this->settings());

        self::assertEqualsWithDelta(30 + 33 * 1.35, $central->hourlyProduction->metal, 1e-9);
        self::assertEqualsWithDelta(30 + 33, $outer->hourlyProduction->metal, 1e-9);
    }

    public function testUniverseSpeedMultipliesProduction(): void
    {
        $output = $this->calculator->compute($this->levels([]), 20, 4, $this->settings(speed: 3.0));

        self::assertEquals(new ResourceRates(90, 45, 0), $output->hourlyProduction);
    }

    public function testStorageLevelsRaiseCapacity(): void
    {
        $output = $this->calculator->compute($this->levels(['metal_storage' => 2]), 20, 4, $this->settings());

        self::assertEquals(new Resources(40_000, 10_000, 10_000), $output->capacity);
    }

    public function testIdlePlanetProducesNothing(): void
    {
        $output = $this->calculator->idle($this->levels([]));

        self::assertEquals(ResourceRates::zero(), $output->hourlyProduction);
        self::assertEquals(new Resources(10_000, 10_000, 10_000), $output->capacity);
    }

    /**
     * @param array<string, int> $levels code => niveau ; les autres types au niveau 0
     *
     * @return list<array{type: BuildingType, level: int}>
     */
    private function levels(array $levels): array
    {
        return array_map(
            static fn(BuildingType $type): array => ['type' => $type, 'level' => $levels[$type->getCode()] ?? 0],
            BuildingTypes::all(),
        );
    }

    private function settings(float $speed = 1.0): EconomySettings
    {
        return new EconomySettings(
            ['metal' => 30, 'crystal' => 15, 'deuterium' => 0],
            ['metal' => 500, 'crystal' => 500, 'deuterium' => 0],
            ['metal' => [6 => 0.17, 7 => 0.23, 8 => 0.35, 9 => 0.23, 10 => 0.17], 'crystal' => [1 => 0.4, 2 => 0.3, 3 => 0.2]],
            $speed,
        );
    }
}
