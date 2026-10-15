<?php

declare(strict_types=1);

namespace App\Enum\FairPlay;

/**
 * Actions notées au journal d'activité d'un joueur (§4.13) : inscription, connexion, actions sensibles.
 */
enum ActivityAction: string
{
    case Registration = 'registration';
    case Login = 'login';
    case FleetDispatch = 'fleet_dispatch';
    case MessageSent = 'message_sent';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Inscription',
            self::Login => 'Connexion',
            self::FleetDispatch => 'Envoi de flotte',
            self::MessageSent => 'Message envoyé',
        };
    }
}
