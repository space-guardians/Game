<?php

declare(strict_types=1);

namespace App\Enum\Market;

/**
 * Cycle d'un contrat de quête (§4.15) : ouvert aux escortes jusqu'au départ, en route, puis réussi (récompense versée
 * aux escortes) ou échoué (récompense rendue au posteur) ; ou annulé par le posteur avant le départ.
 */
enum PlayerQuestStatus: string
{
    case Open = 'open';
    case Underway = 'underway';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouvert',
            self::Underway => 'En route',
            self::Succeeded => 'Réussi',
            self::Failed => 'Échoué',
            self::Cancelled => 'Annulé',
        };
    }

    public function isClosed(): bool
    {
        return \in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }
}
