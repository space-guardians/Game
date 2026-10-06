<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

/**
 * Ligne de la grille de formation (§4.7) : la ligne avant est engagée en premier face à un adversaire de front.
 */
enum FormationRow: string
{
    case Front = 'front';
    case Middle = 'middle';
    case Back = 'back';

    public function label(): string
    {
        return match ($this) {
            self::Front => 'Avant',
            self::Middle => 'Milieu',
            self::Back => 'Arrière',
        };
    }
}
