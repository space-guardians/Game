<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Economy;

use App\Enum\Economy\BuildingEffect;
use App\Model\Economy\Resources;
use App\Service\Economy\BuildingRules;
use PHPUnit\Framework\TestCase;

final class BuildingRulesTest extends TestCase
{
    private BuildingRules $rules;

    protected function setUp(): void
    {
        $this->rules = new BuildingRules();
    }

    public function testCostGrowsExponentiallyWithLevel(): void
    {
        $mine = BuildingTypes::metalMine();

        self::assertEquals(new Resources(60, 15), $this->rules->cost($mine, 1));
        self::assertEquals(new Resources(90, 22.5), $this->rules->cost($mine, 2));
        self::assertEqualsWithDelta(60 * 1.5 ** 9, $this->rules->cost($mine, 10)->metal, 1e-9);
    }

    public function testNoLevelZeroToBuild(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->rules->cost(BuildingTypes::metalMine(), 0);
    }

    public function testMineProduction(): void
    {
        $mine = BuildingTypes::metalMine();

        self::assertSame(0.0, $this->rules->output($mine, 0, 20));
        self::assertEqualsWithDelta(33.0, $this->rules->output($mine, 1, 20), 1e-9);
        self::assertEqualsWithDelta(30 * 10 * 1.1 ** 10, $this->rules->output($mine, 10, 20), 1e-9);
    }

    public function testDeuteriumFavoursColdPlanets(): void
    {
        $synthesizer = BuildingTypes::deuteriumSynthesizer();

        $cold = $this->rules->output($synthesizer, 5, -100);
        $hot = $this->rules->output($synthesizer, 5, 200);

        self::assertEqualsWithDelta(10 * 5 * 1.1 ** 5 * (1.44 + 0.4), $cold, 1e-9);
        self::assertGreaterThan($hot * 2, $cold);
    }

    public function testSolarEnergyFavoursHotPlanets(): void
    {
        $solar = BuildingTypes::solarPlant();

        self::assertGreaterThan($this->rules->output($solar, 5, -100), $this->rules->output($solar, 5, 200));
    }

    public function testConsumptions(): void
    {
        self::assertEqualsWithDelta(11.0, $this->rules->energyConsumption(BuildingTypes::metalMine(), 1), 1e-9);
        self::assertEqualsWithDelta(10 * 3 * 1.1 ** 3, $this->rules->deuteriumConsumption(BuildingTypes::fusionReactor(), 3), 1e-9);
        self::assertSame(0.0, $this->rules->energyConsumption(BuildingTypes::metalMine(), 0));
    }

    public function testStorageCapacityStartsAtTenThousand(): void
    {
        $depot = BuildingTypes::storage(BuildingEffect::MetalStorage);

        self::assertSame(10_000.0, $this->rules->capacity($depot, 0));
        self::assertSame(20_000.0, $this->rules->capacity($depot, 1));
        self::assertSame(40_000.0, $this->rules->capacity($depot, 2));
        self::assertSame(0.0, $this->rules->output($depot, 3, 20));
    }
}
