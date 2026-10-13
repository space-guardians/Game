<?php

declare(strict_types=1);

namespace App\Enum\Messaging;

/**
 * Catégorie d'un message du jeu (§4.12.2) : annonces et rapports automatiques, classés à part dans la messagerie.
 */
enum MessageCategory: string
{
    case Announcement = 'annonce';
    case Combat = 'combat';
    case Espionage = 'espionnage';
    case Transport = 'transport';
    case Expedition = 'expedition';

    public function label(): string
    {
        return match ($this) {
            self::Announcement => 'Annonces',
            self::Combat => 'Combats',
            self::Espionage => 'Espionnage',
            self::Transport => 'Transports',
            self::Expedition => 'Expéditions',
        };
    }
}
