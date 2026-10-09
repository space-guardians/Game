<?php

declare(strict_types=1);

namespace App\Enum\Exploration;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** État d'un événement d'exploration : en attente du choix du joueur, résolu, ou expiré sans réponse */
enum ExplorationEventStatus: string implements TranslatableInterface
{
    case AwaitingChoice = 'awaiting_choice';
    case Resolved = 'resolved';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingChoice => 'En attente de votre décision',
            self::Resolved => 'Résolu',
            self::Expired => 'Expiré',
        };
    }

    /** Ton de la pastille de statut (charte §2.2) */
    public function tone(): string
    {
        return match ($this) {
            self::AwaitingChoice => 'gold',
            self::Resolved => 'green',
            self::Expired => 'neutral',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
