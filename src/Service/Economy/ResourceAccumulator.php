<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Model\Economy\Resources;

/**
 * Règle d'accumulation des ressources (§4.2), sans base de données : stock + production horaire × durée, plafonné
 * par la capacité de stockage. L'excédent est perdu ; un stock déjà au-delà de la capacité (butin, remboursement…)
 * n'est pas réduit, il cesse simplement de croître.
 */
final class ResourceAccumulator
{
    public function accumulate(Resources $stock, Resources $hourlyProduction, Resources $capacity, float $hours): Resources
    {
        if ($hours <= 0) {
            return $stock;
        }

        return $stock->plus($hourlyProduction->times($hours))->min($capacity->max($stock));
    }
}
