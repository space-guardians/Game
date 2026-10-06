<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Action effectuée à l'arrivée d'un ordre « se déplacer puis agir » (§4.6). Les autres actions (espionnage,
 * recyclage, exploration, bataille) arrivent avec leurs phases.
 */
enum FleetAction: string implements TranslatableInterface
{
    /** Décharge toute la cargaison sur place ; cible une planète */
    case Transport = 'transport';
    /** Reste sur place, sans autre action (renfort, point de ralliement, retour) */
    case Station = 'station';
    /** Fonde une colonie sur une planète libre ; consomme un colonisateur */
    case Colonize = 'colonize';
    /** Livre le deutérium de la cargaison à une flotte immobilisée sur place (§4.6.3) */
    case Refuel = 'refuel';

    public function label(): string
    {
        return match ($this) {
            self::Transport => 'Transport de ressources',
            self::Station => 'Stationner',
            self::Colonize => 'Coloniser',
            self::Refuel => 'Ravitaillement',
        };
    }

    public function requiresPlanet(): bool
    {
        return self::Transport === $this || self::Colonize === $this;
    }

    /** Action saisie par coordonnées ; le ravitaillement, lui, vise une flotte (sa position exacte) */
    public function targetsCoordinates(): bool
    {
        return self::Refuel !== $this;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
