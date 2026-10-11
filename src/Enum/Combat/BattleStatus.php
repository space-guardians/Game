<?php

declare(strict_types=1);

namespace App\Enum\Combat;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** État d'une bataille programmée (§4.7) */
enum BattleStatus: string implements TranslatableInterface
{
    /** Programmée : des flottes peuvent encore la rejoindre jusqu'à l'heure de résolution */
    case Scheduled = 'scheduled';
    case Resolved = 'resolved';
    /** Annulée : aucun assaillant sur place à l'heure de résolution */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programmée',
            self::Resolved => 'Résolue',
            self::Cancelled => 'Annulée',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
