<?php

declare(strict_types=1);

namespace App\Enum\Notification;

/**
 * Événements de jeu notifiés au joueur (§4.12.3) : alertes courtes, distinctes de la messagerie.
 */
enum NotificationType: string
{
    case BuildingCompleted = 'building';
    case ResearchCompleted = 'research';
    case ShipyardCompleted = 'shipyard';
    case FleetArrived = 'fleet';
    case IncomingAttack = 'attack';
    case MessageReceived = 'message';

    public function label(): string
    {
        return match ($this) {
            self::BuildingCompleted => 'Construction',
            self::ResearchCompleted => 'Recherche',
            self::ShipyardCompleted => 'Chantier spatial',
            self::FleetArrived => 'Flotte',
            self::IncomingAttack => 'Attaque entrante',
            self::MessageReceived => 'Message',
        };
    }

    /** Teinte de la puce (charte) */
    public function tone(): string
    {
        return match ($this) {
            self::IncomingAttack => 'red',
            self::MessageReceived => 'violet',
            self::FleetArrived => 'cyan',
            default => 'gold',
        };
    }
}
