<?php

declare(strict_types=1);

namespace App\Enum\Market;

/**
 * Cycle d'une planète mise sur le marché (§4.15) : ouverte ; pour un échange, une planète proposée en retour attend la
 * réponse du vendeur ; conclue (la planète a changé de mains) ou retirée.
 */
enum PlanetListingStatus: string
{
    case Open = 'open';
    case Offered = 'offered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouverte',
            self::Offered => 'Échange proposé',
            self::Completed => 'Conclue',
            self::Cancelled => 'Retirée',
        };
    }
}
