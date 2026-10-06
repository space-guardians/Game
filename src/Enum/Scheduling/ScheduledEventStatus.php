<?php

declare(strict_types=1);

namespace App\Enum\Scheduling;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle de vie d'un événement planifié : en attente de son échéance, puis résolu, en échec ou annulé.
 */
enum ScheduledEventStatus: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Done => 'Résolu',
            self::Failed => 'Échec',
            self::Cancelled => 'Annulé',
        };
    }

    /** Style du badge dans le panneau (EasyAdmin) */
    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Done => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'light',
        };
    }

    /** Libellé affiché par les listes et filtres d'EasyAdmin ; l'interface est en français */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
