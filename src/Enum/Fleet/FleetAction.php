<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Action effectuée à l'arrivée d'un ordre « se déplacer puis agir » (§4.6).
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
    /** Résolution d'exploration : une quête ou un événement peut apparaître (§4.6.4) ; ne cible pas de planète */
    case Explore = 'explore';
    /**
     * Attaque les flottes hostiles présentes sur place (§4.7) : le départ programme une bataille au lieu visé, ou rejoint
     * celle qui s'y tiendra ; la flotte l'attend sur place, puis reprend ses ordres
     */
    case JoinBattle = 'battle';
    /** Récupère le champ de débris présent sur place ; il faut des recycleurs dans la flotte */
    case Recycle = 'recycle';
    /**
     * Rapport sur une planète, ou sur les flottes présentes à une position (§4.9) ; la flotte ne compte que des sondes
     * d'espionnage
     */
    case Spy = 'spy';

    /** Entrer dans une porte de téléportation de son alliance : la flotte ressort par la porte reliée (§4.10) */
    case Teleport = 'teleport';

    public function label(): string
    {
        return match ($this) {
            self::Transport => 'Transport de ressources',
            self::Station => 'Stationner',
            self::Colonize => 'Coloniser',
            self::Refuel => 'Ravitaillement',
            self::Explore => 'Exploration',
            self::JoinBattle => 'Rejoindre une bataille',
            self::Recycle => 'Recyclage',
            self::Spy => 'Espionnage',
            self::Teleport => 'Téléportation',
        };
    }

    public function requiresPlanet(): bool
    {
        return self::Transport === $this || self::Colonize === $this || self::Teleport === $this;
    }

    public function forbidsPlanet(): bool
    {
        return self::Explore === $this;
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
