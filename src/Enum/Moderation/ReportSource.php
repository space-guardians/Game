<?php

declare(strict_types=1);

namespace App\Enum\Moderation;

/**
 * Origine d'un message signalé (§4.13) : chat en ligne (non persistant, extrait tiré du tampon) ou messagerie.
 */
enum ReportSource: string
{
    case Chat = 'chat';
    case Messaging = 'messaging';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Messaging => 'Messagerie',
        };
    }
}
