<?php

declare(strict_types=1);

namespace App\Enum\Pve;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Catégorie d'une civilisation non-joueuse (§4.14) : mineure (raids ponctuels, cible abordable en solo), « boss »
 * gardienne (strictement défensive, flotte indexée sur le niveau du serveur) ou guilde marchande (convois à escorter,
 * §4.15).
 */
enum NpcCategory: string implements TranslatableInterface
{
    case Minor = 'minor';
    case Boss = 'boss';
    case Trader = 'trader';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Civilisation mineure',
            self::Boss => 'Civilisation « boss » gardienne',
            self::Trader => 'Guilde marchande',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
