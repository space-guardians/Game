<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\BuildingType;
use App\Model\Economy\EconomySettings;
use App\Model\Economy\PlanetOutput;
use App\Model\Economy\ResourceRates;
use App\Model\Economy\Resources;

/**
 * Bilan d'une planète à partir des niveaux de ses bâtiments (§4.2), sans base de données :
 * - production de base + production des mines × facteur d'énergie × bonus d'orbite, × vitesse d'univers ;
 * - énergie : en déficit, la production des mines est réduite au prorata (production / consommation) ;
 * - deutérium net : production des synthétiseurs − consommation de la centrale à fusion ;
 * - capacités : celles des dépôts (niveau 0 compris).
 */
final readonly class ProductionCalculator
{
    public function __construct(
        private BuildingRules $rules,
    ) {}

    /**
     * @param list<array{type: BuildingType, level: int}> $buildings tous les types existants, niveau 0 compris
     */
    public function compute(array $buildings, int $temperature, int $orbit, EconomySettings $settings): PlanetOutput
    {
        $energyProduced = $energyConsumed = $fusionDeuterium = 0.0;
        $mines = ResourceRates::zero();
        $capacity = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];

        foreach ($buildings as ['type' => $type, 'level' => $level]) {
            $effect = $type->getEffect();
            $energyConsumed += $this->rules->energyConsumption($type, $level);
            $fusionDeuterium += $this->rules->deuteriumConsumption($type, $level);

            if ($effect->isEnergy()) {
                $energyProduced += $this->rules->output($type, $level, $temperature);
            } elseif ($effect->isProduction()) {
                $resource = (string) $effect->resource();
                $bonus = 1 + ($settings->orbitBonuses[$resource][$orbit] ?? 0.0);
                $mines = $mines->with($resource, $mines->get($resource) + $this->rules->output($type, $level, $temperature) * $bonus);
            } elseif ($effect->isStorage()) {
                $capacity[(string) $effect->resource()] += $this->rules->capacity($type, $level);
            }
        }

        $factor = $energyConsumed > 0 ? min(1.0, $energyProduced / $energyConsumed) : 1.0;
        $production = ResourceRates::of($settings->baseHourlyProduction)
            ->plus($mines->times($factor))
            ->times($settings->universeSpeed)
            ->plus(new ResourceRates(deuterium: -$fusionDeuterium * $settings->universeSpeed));

        return new PlanetOutput(
            $production,
            new Resources($capacity['metal'], $capacity['crystal'], $capacity['deuterium']),
            $energyProduced,
            $energyConsumed,
            $factor,
        );
    }

    /**
     * Planète sans propriétaire : rien ne produit, seuls les dépôts (niveau 0) comptent.
     *
     * @param list<array{type: BuildingType, level: int}> $buildings
     */
    public function idle(array $buildings): PlanetOutput
    {
        $capacity = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        foreach ($buildings as ['type' => $type, 'level' => $level]) {
            if ($type->getEffect()->isStorage()) {
                $capacity[(string) $type->getEffect()->resource()] += $this->rules->capacity($type, $level);
            }
        }

        return new PlanetOutput(ResourceRates::zero(), new Resources($capacity['metal'], $capacity['crystal'], $capacity['deuterium']));
    }
}
