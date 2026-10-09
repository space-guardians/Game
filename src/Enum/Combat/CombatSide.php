<?php

declare(strict_types=1);

namespace App\Enum\Combat;

/** Camp d'un combat (§4.7) */
enum CombatSide: string
{
    case Attacker = 'attacker';
    case Defender = 'defender';

    public function label(): string
    {
        return match ($this) {
            self::Attacker => 'Attaquant',
            self::Defender => 'Défenseur',
        };
    }

    public function opponent(): self
    {
        return self::Attacker === $this ? self::Defender : self::Attacker;
    }
}
