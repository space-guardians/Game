<?php

declare(strict_types=1);

namespace App\Service\Ranking;

use App\Model\Economy\Resources;
use App\Model\Ranking\ScoreBreakdown;

/**
 * Règles des scores (§4.11), pures : un point par tranche de 1 000 ressources investies (bâtiments, recherches,
 * vaisseaux et défenses), des points fixes par colonie et par événement d'exploration vécu, et un score global somme
 * pondérée des catégories. Valeurs provisoires (§7).
 */
final readonly class RankingRules
{
    public const float RESOURCES_PER_POINT = 1000.0;

    public const int POINTS_PER_COLONY = 50;

    public const int POINTS_PER_EXPLORATION = 5;

    /** Poids des catégories dans le score global */
    public const float ECONOMY_WEIGHT = 1.0;
    public const float RESEARCH_WEIGHT = 1.0;
    public const float MILITARY_WEIGHT = 1.0;
    public const float EXPLORATION_WEIGHT = 1.0;

    /**
     * Ressources investies pour atteindre un niveau : coût de base × facteur^(n − 1), cumulé du niveau 1 au niveau
     * atteint (bâtiments et recherches suivent la même progression).
     */
    public function levelsCost(Resources $baseCost, float $costFactor, int $level): float
    {
        if ($level <= 0) {
            return 0.0;
        }
        $base = $baseCost->metal + $baseCost->crystal + $baseCost->deuterium;
        $multiplier = abs($costFactor - 1.0) < 1e-9 ? $level : ($costFactor ** $level - 1.0) / ($costFactor - 1.0);

        return $base * $multiplier;
    }

    public function unitsCost(Resources $unitCost, int $quantity): float
    {
        return max(0, $quantity) * ($unitCost->metal + $unitCost->crystal + $unitCost->deuterium);
    }

    /**
     * @param float $buildings ressources investies dans les bâtiments
     * @param float $research  ressources investies dans les recherches
     * @param float $ships     ressources investies dans les vaisseaux et défenses existants
     * @param int   $colonies  planètes hors planète mère
     */
    public function score(float $buildings, float $research, float $ships, int $colonies, int $explorations): ScoreBreakdown
    {
        $economy = (int) floor($buildings / self::RESOURCES_PER_POINT);
        $researchPoints = (int) floor($research / self::RESOURCES_PER_POINT);
        $military = (int) floor($ships / self::RESOURCES_PER_POINT);
        $exploration = max(0, $colonies) * self::POINTS_PER_COLONY + max(0, $explorations) * self::POINTS_PER_EXPLORATION;

        return new ScoreBreakdown($economy, $researchPoints, $military, $exploration, (int) round(
            $economy * self::ECONOMY_WEIGHT
            + $researchPoints * self::RESEARCH_WEIGHT
            + $military * self::MILITARY_WEIGHT
            + $exploration * self::EXPLORATION_WEIGHT,
        ));
    }
}
