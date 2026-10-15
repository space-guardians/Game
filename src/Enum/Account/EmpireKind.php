<?php

declare(strict_types=1);

namespace App\Enum\Account;

/**
 * Empire d'un joueur (rattaché à un compte) ou civilisation non-joueuse gérée par le moteur de jeu (§4.14).
 */
enum EmpireKind: string
{
    case Player = 'player';
    case Npc = 'npc';
}
