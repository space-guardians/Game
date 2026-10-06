<?php

declare(strict_types=1);

namespace App\Model\Scheduling;

use App\Entity\Empire;
use App\Entity\Planet;

/**
 * Topics Mercure du jeu (§5.2), privés : seul le joueur concerné y est abonné.
 */
final class GameTopics
{
    public static function planet(Planet $planet): string
    {
        return '/planet/' . $planet->getId();
    }

    public static function empire(Empire $empire): string
    {
        return '/empire/' . $empire->getId();
    }
}
