<?php

declare(strict_types=1);

namespace App\Enum\Event;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Portée d'un événement de jeu (§4.16) : tout l'univers, une galaxie, ou une zone circulaire d'une galaxie.
 */
enum GameEventScope: string implements TranslatableInterface
{
    case Universe = 'universe';
    case Galaxy = 'galaxy';
    case Zone = 'zone';

    public function label(): string
    {
        return match ($this) {
            self::Universe => 'Tout l’univers',
            self::Galaxy => 'Une galaxie',
            self::Zone => 'Une zone d’une galaxie',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
