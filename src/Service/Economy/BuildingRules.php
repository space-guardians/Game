<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\BuildingType;
use App\Model\Economy\Resources;

/**
 * Formules des bâtiments (§4.3), sans base de données ; leurs paramètres viennent du type de bâtiment, réglable
 * dans le panneau. Reprises des OGame-like classiques, à équilibrer (§7).
 *
 * - coût du niveau n : coût de base × facteur^(n − 1) ;
 * - production et énergie : base × n × croissance^n, modulées par la température ;
 * - consommations (énergie des mines, deutérium de la fusion) : base × n × 1,1^n ;
 * - stockage : base × ⌊2,5 × e^(20n / 33)⌋ (10 000 au niveau 0 pour une base de 5 000) ;
 * - durée de construction (heures) : (métal + cristal) / (2 500 × (1 + robots) × 2^nanites) / vitesse d'univers.
 */
final class BuildingRules
{
    private const float CONSUMPTION_GROWTH = 1.1;
    private const float CONSTRUCTION_DIVISOR = 2500.0;

    public function cost(BuildingType $type, int $level): Resources
    {
        if ($level < 1) {
            throw new \InvalidArgumentException('Le premier niveau constructible est le niveau 1.');
        }

        return $type->getBaseCost()->times($type->getCostFactor() ** ($level - 1));
    }

    /** Production horaire (ressource) ou énergie produite, avant le facteur d'énergie et les bonus d'orbite */
    public function output(BuildingType $type, int $level, int $temperature): float
    {
        if ($level <= 0 || $type->getEffect()->isStorage()) {
            return 0.0;
        }

        $raw = $type->getEffectBase() * $level * $type->getEffectGrowth() ** $level;

        return max(0.0, $raw * ($type->getTemperatureBase() + $type->getTemperatureCoefficient() * $temperature));
    }

    public function energyConsumption(BuildingType $type, int $level): float
    {
        return $this->consumption($type->getEnergyConsumption(), $level);
    }

    public function deuteriumConsumption(BuildingType $type, int $level): float
    {
        return $this->consumption($type->getDeuteriumConsumption(), $level);
    }

    /** Capacité de stockage d'un dépôt, niveau 0 compris */
    public function capacity(BuildingType $type, int $level): float
    {
        if (!$type->getEffect()->isStorage()) {
            return 0.0;
        }

        return $type->getEffectBase() * floor(2.5 * exp(20 * max(0, $level) / 33));
    }

    /** Durée de construction en secondes (au moins une), selon le coût et les usines de la planète */
    public function constructionSeconds(Resources $cost, int $robotFactoryLevel, int $naniteFactoryLevel, float $universeSpeed): int
    {
        $hours = ($cost->metal + $cost->crystal) / (self::CONSTRUCTION_DIVISOR * (1 + $robotFactoryLevel) * 2 ** $naniteFactoryLevel) / $universeSpeed;

        return max(1, (int) ceil($hours * 3600));
    }

    private function consumption(float $base, int $level): float
    {
        return $level <= 0 ? 0.0 : $base * $level * self::CONSUMPTION_GROWTH ** $level;
    }
}
