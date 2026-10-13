<?php

declare(strict_types=1);

namespace App\Enum\Messaging;

/**
 * Destinataires d'un message système (§4.12.2).
 */
enum SystemAudience: string
{
    case Empires = 'empires';
    case Alliance = 'alliance';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Empires => 'Des joueurs',
            self::Alliance => 'Une alliance',
            self::All => 'Tous les joueurs',
        };
    }
}
