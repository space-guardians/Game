<?php

declare(strict_types=1);

namespace App\Enum\Market;

/**
 * Cycle d'une annonce du marché (§4.15) : ouverte à tous, puis conclue avec un acheteur ; validée quand les deux
 * parties ont livré leur dû au marchand ; ou retirée par le vendeur tant qu'aucun acheteur ne s'est engagé.
 */
enum MarketListingStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouverte',
            self::Accepted => 'En attente des livraisons',
            self::Completed => 'Validée',
            self::Cancelled => 'Retirée',
        };
    }
}
