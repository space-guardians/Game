<?php

declare(strict_types=1);

namespace App\Model\Combat;

/**
 * Matrice des classes (§4.7), par codes de classe : multiplicateur des dégâts d'une classe attaquante contre une
 * classe visée. Une paire absente — ou un vaisseau sans classe (civil) — est neutre (×1). Utilisée par le combat,
 * sans base.
 */
final readonly class MatchupMatrix
{
    public const float NEUTRAL = 1.0;

    /**
     * @param array<string, array<string, float>> $multipliers [attaquante][visée]
     */
    public function __construct(
        private array $multipliers = [],
    ) {}

    public function multiplier(?string $attackerClass, ?string $defenderClass): float
    {
        if (null === $attackerClass || null === $defenderClass) {
            return self::NEUTRAL;
        }

        return $this->multipliers[$attackerClass][$defenderClass] ?? self::NEUTRAL;
    }

    /** @return array<string, array<string, float>> paires non neutres */
    public function toArray(): array
    {
        return $this->multipliers;
    }
}
