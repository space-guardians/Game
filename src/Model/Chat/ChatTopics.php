<?php

declare(strict_types=1);

namespace App\Model\Chat;

use App\Entity\User;

/**
 * Topics Mercure du chat (§5.7), privés : le canal public partagé, et le topic personnel de chaque joueur, sur
 * lequel le serveur relaie les messages d'alliance, privés et de groupe (fan-out). Le cookie d'abonnement n'a ainsi
 * besoin que de ces deux topics fixes.
 */
final class ChatTopics
{
    public const string PUBLIC = '/chat/public';

    public static function user(User $user): string
    {
        return '/users/' . $user->getId() . '/chat';
    }
}
