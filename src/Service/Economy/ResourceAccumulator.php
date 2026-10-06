<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Model\Economy\ResourceRates;
use App\Model\Economy\Resources;

/**
 * Règle d'accumulation des ressources (§4.2), sans base de données : stock + débit horaire × durée.
 * - Débit positif : plafonné par la capacité de stockage, l'excédent est perdu ; un stock déjà au-delà de la
 *   capacité (butin, remboursement…) n'est pas réduit, il cesse simplement de croître.
 * - Débit négatif (deutérium consommé par la fusion) : le stock descend jusqu'à 0, jamais en dessous.
 */
final class ResourceAccumulator
{
    public function accumulate(Resources $stock, ResourceRates $hourly, Resources $capacity, float $hours): Resources
    {
        if ($hours <= 0) {
            return $stock;
        }

        return new Resources(
            $this->one($stock->metal, $hourly->metal, $capacity->metal, $hours),
            $this->one($stock->crystal, $hourly->crystal, $capacity->crystal, $hours),
            $this->one($stock->deuterium, $hourly->deuterium, $capacity->deuterium, $hours),
        );
    }

    private function one(float $stock, float $rate, float $capacity, float $hours): float
    {
        $value = $stock + $rate * $hours;

        return $rate >= 0 ? min($value, max($capacity, $stock)) : max(0.0, $value);
    }
}
