<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Rôles du panneau d'administration, du plus restreint au plus étendu : chacun inclut les droits des
 * précédents. La valeur est le rôle Symfony ; la hiérarchie est déclarée à l'identique dans security.yaml
 * (role_hierarchy), ce que vérifie AdminRoleHierarchyTest.
 *
 * @see §5.6.2 du cahier des charges
 */
enum AdminRole: string
{
    case Moderator = 'ROLE_MODERATOR';
    case GameDesigner = 'ROLE_GAME_DESIGNER';
    case Admin = 'ROLE_ADMIN';
    case SuperAdmin = 'ROLE_SUPER_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::Moderator => 'Modération',
            self::GameDesigner => 'Game design',
            self::Admin => 'Administration',
            self::SuperAdmin => 'Super administration',
        };
    }

    /** Ce rôle donne-t-il au moins les droits de $other ? */
    public function includes(self $other): bool
    {
        return array_search($this, self::cases(), true) >= array_search($other, self::cases(), true);
    }

    /** Liste lisible des valeurs acceptées, pour les messages d'erreur et l'aide */
    public static function valuesList(): string
    {
        return implode(', ', array_column(self::cases(), 'value'));
    }
}
