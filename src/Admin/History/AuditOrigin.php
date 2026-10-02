<?php

declare(strict_types=1);

namespace App\Admin\History;

/**
 * Origine d'une modification, déduite du pare-feu enregistré par l'auditeur : joueurs et administrateurs ont des
 * identifiants qui se recoupent, seul le pare-feu les distingue.
 */
enum AuditOrigin: string
{
    case Admin = 'admin';
    case Player = 'main';
    /** Commande, tâche de fond, ou écriture sans compte connecté */
    case System = 'system';

    public static function fromFirewall(?string $firewall): self
    {
        return self::tryFrom((string) $firewall) ?? self::System;
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administration',
            self::Player => 'Joueur',
            self::System => 'Système',
        };
    }
}
