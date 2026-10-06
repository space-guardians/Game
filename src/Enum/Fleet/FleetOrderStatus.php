<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

/**
 * Avancement d'un ordre du carnet : à venir, en cours (la flotte s'y rend), fait, ou abandonné (action impossible à
 * l'arrivée).
 */
enum FleetOrderStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À venir',
            self::InProgress => 'En cours',
            self::Done => 'Fait',
            self::Failed => 'Abandonné',
        };
    }
}
