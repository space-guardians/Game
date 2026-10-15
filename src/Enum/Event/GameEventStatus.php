<?php

declare(strict_types=1);

namespace App\Enum\Event;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle d'un événement de jeu (§4.16) : planifié, en cours (entre son début et sa fin), terminé, ou annulé.
 */
enum GameEventStatus: string implements TranslatableInterface
{
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Planifié',
            self::Running => 'En cours',
            self::Finished => 'Terminé',
            self::Cancelled => 'Annulé',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
