<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Model\Economy\Resources;

/**
 * Règles du pillage (§4.7), sans état ni base. Valeurs provisoires avant l'équilibrage (§7) : l'assaillant victorieux
 * emporte au plus la moitié de chaque ressource de la planète, dans la limite du cargo libre de ses flottes
 * survivantes (réduction proportionnelle si elles ne peuvent pas tout prendre) ; le butin se partage entre ses flottes
 * au prorata de leur cargo libre.
 */
final readonly class PillageRules
{
    public const float PLUNDER_RATIO = 0.5;

    /** Butin emporté sur un stock, pour une capacité libre totale (part : la moitié, ou celle du profil d'une civilisation) */
    public function loot(Resources $stock, float $capacity, float $ratio = self::PLUNDER_RATIO): Resources
    {
        $wanted = new Resources(
            max(0.0, $stock->metal) * $ratio,
            max(0.0, $stock->crystal) * $ratio,
            max(0.0, $stock->deuterium) * $ratio,
        );
        $total = $wanted->metal + $wanted->crystal + $wanted->deuterium;
        if ($total <= 0 || $capacity <= 0) {
            return new Resources();
        }
        $ratio = min(1.0, $capacity / $total);

        return new Resources(floor($wanted->metal * $ratio), floor($wanted->crystal * $ratio), floor($wanted->deuterium * $ratio));
    }

    /**
     * Partage du butin au prorata du cargo libre de chaque flotte, sans dépasser sa part (arrondi à l'unité inférieure).
     *
     * @param array<int, float> $capacities cargo libre, par identifiant de flotte
     *
     * @return array<int, Resources> par identifiant de flotte
     */
    public function share(Resources $loot, array $capacities): array
    {
        $total = array_sum($capacities);
        $shares = [];
        foreach ($capacities as $fleetId => $capacity) {
            $part = $total > 0 ? max(0.0, $capacity) / $total : 0.0;
            $shares[$fleetId] = new Resources(floor($loot->metal * $part), floor($loot->crystal * $part), floor($loot->deuterium * $part));
        }

        return $shares;
    }
}
