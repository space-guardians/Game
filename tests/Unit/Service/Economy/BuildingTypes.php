<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Economy;

use App\Entity\BuildingType;
use App\Enum\Economy\BuildingEffect;
use App\Model\Economy\Resources;

/**
 * Types de bâtiments de départ (mêmes paramètres que la migration), pour les tests sans base de données.
 */
final class BuildingTypes
{
    public static function metalMine(): BuildingType
    {
        return self::make('metal_mine', BuildingEffect::MetalProduction, new Resources(60, 15), 1.5, 30, 1.1, energy: 10);
    }

    public static function crystalMine(): BuildingType
    {
        return self::make('crystal_mine', BuildingEffect::CrystalProduction, new Resources(48, 24), 1.6, 20, 1.1, energy: 10);
    }

    public static function deuteriumSynthesizer(): BuildingType
    {
        return self::make('deuterium_synthesizer', BuildingEffect::DeuteriumProduction, new Resources(225, 75), 1.5, 10, 1.1, energy: 20, temperatureBase: 1.44, temperatureCoefficient: -0.004);
    }

    public static function solarPlant(): BuildingType
    {
        return self::make('solar_plant', BuildingEffect::SolarEnergy, new Resources(75, 30), 1.5, 20, 1.1, temperatureCoefficient: 0.002);
    }

    public static function fusionReactor(): BuildingType
    {
        return self::make('fusion_reactor', BuildingEffect::FusionEnergy, new Resources(900, 360, 180), 1.8, 30, 1.05, deuterium: 10);
    }

    public static function storage(BuildingEffect $effect): BuildingType
    {
        return self::make($effect->value, $effect, new Resources(1000), 2, 5000, 1);
    }

    /** @return list<BuildingType> */
    public static function all(): array
    {
        return [
            self::metalMine(), self::crystalMine(), self::deuteriumSynthesizer(), self::solarPlant(), self::fusionReactor(),
            self::storage(BuildingEffect::MetalStorage), self::storage(BuildingEffect::CrystalStorage), self::storage(BuildingEffect::DeuteriumStorage),
        ];
    }

    private static function make(
        string $code,
        BuildingEffect $effect,
        Resources $cost,
        float $costFactor,
        float $base,
        float $growth,
        float $energy = 0,
        float $deuterium = 0,
        float $temperatureBase = 1,
        float $temperatureCoefficient = 0,
    ): BuildingType {
        $type = new BuildingType($code, $code, $effect);
        $type->setBaseCost($cost);
        $type->setCostFactor($costFactor);
        $type->setEffectBase($base);
        $type->setEffectGrowth($growth);
        $type->setEnergyConsumption($energy);
        $type->setDeuteriumConsumption($deuterium);
        $type->setTemperatureBase($temperatureBase);
        $type->setTemperatureCoefficient($temperatureCoefficient);

        return $type;
    }
}
