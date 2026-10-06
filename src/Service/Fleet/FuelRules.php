<?php

declare(strict_types=1);

namespace App\Service\Fleet;

/**
 * Consommation de carburant d'un trajet (§4.6.3), sans accès aux données : distance, consommation propre de chaque
 * type de vaisseau, propulsion et pourcentage de vitesse, agrégés sur toute la flotte.
 */
final readonly class FuelRules
{
    /** Distance de référence de la consommation de base d'un vaisseau */
    private const float REFERENCE_DISTANCE = 35_000.0;

    /**
     * Facteur de consommation par propulsion : les propulsions rapides brûlent davantage. Valeurs provisoires avant
     * l'équilibrage (§7).
     */
    public const array DRIVE_FACTORS = [
        'combustion_drive' => 1.0,
        'impulse_drive' => 1.5,
        'hyperspace_drive' => 2.0,
    ];

    /**
     * Deutérium consommé par le trajet : Σ consommation × nombre × facteur de propulsion × distance / 35 000
     * × (1 + % / 100)², arrondi à l'unité supérieure (formule OGame, en continu sur la distance).
     *
     * @param list<array{consumption: int, quantity: int, drive: ?string}> $ships
     */
    public function consumption(array $ships, float $distance, int $speedPercent): float
    {
        if ($distance <= 0.0) {
            return 0.0;
        }
        $perDistance = 0.0;
        foreach ($ships as ['consumption' => $consumption, 'quantity' => $quantity, 'drive' => $drive]) {
            $perDistance += $consumption * $quantity * (self::DRIVE_FACTORS[$drive ?? ''] ?? 1.0);
        }

        return ceil($perDistance * $distance / self::REFERENCE_DISTANCE * (1 + $speedPercent / 100) ** 2);
    }

    /**
     * Part du trajet que le carburant permet de couvrir (1 si le réservoir suffit) : la flotte tombe en panne à cette
     * fraction de la distance, donc du temps de trajet.
     */
    public function reach(float $fuel, float $consumption): float
    {
        if ($consumption <= 0.0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $fuel / $consumption));
    }
}
