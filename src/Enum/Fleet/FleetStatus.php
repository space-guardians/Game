<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * État d'une flotte (§4.6) : stationnée à une position, en vol vers la destination de son ordre en cours, ou
 * immobilisée faute de carburant (#38).
 */
enum FleetStatus: string implements TranslatableInterface
{
    case Stationed = 'stationed';
    case InFlight = 'in_flight';
    case Stranded = 'stranded';

    public function label(): string
    {
        return match ($this) {
            self::Stationed => 'Stationnée',
            self::InFlight => 'En vol',
            self::Stranded => 'Immobilisée',
        };
    }

    /** Couleur du badge (charte : cyan pour ce qui bouge en temps réel) */
    public function tone(): string
    {
        return match ($this) {
            self::Stationed => 'neutral',
            self::InFlight => 'cyan',
            self::Stranded => 'red',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
