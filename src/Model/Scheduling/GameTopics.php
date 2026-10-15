<?php

declare(strict_types=1);

namespace App\Model\Scheduling;

use App\Entity\Alliance;
use App\Entity\Empire;
use App\Entity\Planet;

/**
 * Topics Mercure du jeu (§5.2), privés, un par portée : planète (ce qui ne concerne qu'elle : fin de construction,
 * livraison du chantier), empire (recherche, flottes, messagerie, notifications…) et alliance (alertes partagées par
 * ses membres, publiées une seule fois). Le joueur est abonné à son empire, à chacune de ses planètes et à son alliance.
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

    public static function alliance(Alliance $alliance): string
    {
        return '/alliance/' . $alliance->getId();
    }
}
