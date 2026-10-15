<?php

declare(strict_types=1);

namespace App\Model\Balance;

/**
 * Résultat de N combats simulés entre deux compositions (§7, passe d'équilibrage) : victoires de chaque camp, nuls,
 * nombre moyen de rounds et part moyenne de vaisseaux perdus par camp (0 à 1).
 */
final readonly class CombatSimulation
{
    public function __construct(
        public int $runs,
        public int $attackerWins,
        public int $defenderWins,
        public float $averageRounds,
        public float $attackerLosses,
        public float $defenderLosses,
    ) {}

    public function draws(): int
    {
        return $this->runs - $this->attackerWins - $this->defenderWins;
    }
}
