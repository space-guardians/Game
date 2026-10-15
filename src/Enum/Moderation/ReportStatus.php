<?php

declare(strict_types=1);

namespace App\Enum\Moderation;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Traitement d'un signalement (§4.13) : à traiter, traité (une sanction a suivi) ou classé sans suite.
 */
enum ReportStatus: string implements TranslatableInterface
{
    case Open = 'open';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'À traiter',
            self::Actioned => 'Traité',
            self::Dismissed => 'Classé sans suite',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
