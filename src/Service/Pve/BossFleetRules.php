<?php

declare(strict_types=1);

namespace App\Service\Pve;

/**
 * Flotte d'une civilisation « boss » (§4.14), pur : une composition de base mise à l'échelle pour valoir une cible en
 * points militaires (§4.11 : 1 point par 1 000 ressources investies), au moins un vaisseau de chaque type. La cible est
 * la flotte militaire moyenne du top 10 des joueurs, multipliée par la force du profil.
 */
final class BossFleetRules
{
    /** Composition de base (codes de types), mise à l'échelle (provisoire) */
    public const array BASE = ['light_fighter' => 150, 'cruiser' => 60];

    /**
     * @param list<int> $topMilitary points militaires des meilleurs joueurs
     */
    public function target(array $topMilitary, int $strengthPercent): float
    {
        if ([] === $topMilitary) {
            return 0.0;
        }

        return array_sum($topMilitary) / \count($topMilitary) * $strengthPercent / 100;
    }

    /**
     * @param array<string, int>   $base       quantités de base par type
     * @param array<string, float> $unitPoints points militaires d'un vaisseau, par type
     *
     * @return array<string, int> quantités visées par type
     */
    public function composition(array $base, array $unitPoints, float $targetPoints): array
    {
        $basePoints = 0.0;
        foreach ($base as $code => $quantity) {
            $basePoints += $quantity * ($unitPoints[$code] ?? 0.0);
        }
        $factor = $basePoints > 0 ? $targetPoints / $basePoints : 0.0;
        $composition = [];
        foreach ($base as $code => $quantity) {
            $composition[$code] = max(1, (int) round($quantity * $factor));
        }

        return $composition;
    }
}
