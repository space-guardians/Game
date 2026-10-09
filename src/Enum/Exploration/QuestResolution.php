<?php

declare(strict_types=1);

namespace App\Enum\Exploration;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Résolution d'un événement d'exploration (§4.6.4) : tirage pondéré entre ses issues, ou choix du joueur */
enum QuestResolution: string implements TranslatableInterface
{
    case Automatic = 'automatic';
    case PlayerChoice = 'choice';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatique (tirage pondéré)',
            self::PlayerChoice => 'Choix du joueur',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
