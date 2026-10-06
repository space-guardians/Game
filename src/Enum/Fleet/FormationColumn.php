<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

/**
 * Colonne de la grille de formation (§4.7) : les flancs comptent quand des flottes en mouvement convergent.
 */
enum FormationColumn: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

    public function label(): string
    {
        return match ($this) {
            self::Left => 'Gauche',
            self::Center => 'Centre',
            self::Right => 'Droite',
        };
    }
}
