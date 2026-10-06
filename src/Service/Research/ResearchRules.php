<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\Technology;
use App\Model\Economy\Resources;

/**
 * Formules de la recherche (§4.4), sans accès aux données : coût d'un niveau, durée selon les laboratoires de
 * l'empire.
 */
final readonly class ResearchRules
{
    private const float RESEARCH_DIVISOR = 1000.0;

    /** Coût du niveau visé : coût de base × facteur^(niveau − 1), comme pour les bâtiments */
    public function cost(Technology $technology, int $level): Resources
    {
        if ($level < 1) {
            throw new \InvalidArgumentException('Le premier niveau de recherche est le niveau 1.');
        }

        return $technology->getBaseCost()->times($technology->getCostFactor() ** ($level - 1));
    }

    /**
     * Durée de recherche en secondes (au moins une) : (métal + cristal) / (1 000 × (1 + laboratoires)) heures, où
     * laboratoires est la somme des niveaux de tous les laboratoires de l'empire.
     */
    public function durationSeconds(Resources $cost, int $laboratoryLevels, float $universeSpeed): int
    {
        $hours = ($cost->metal + $cost->crystal) / (self::RESEARCH_DIVISOR * (1 + $laboratoryLevels)) / $universeSpeed;

        return max(1, (int) ceil($hours * 3600));
    }
}
