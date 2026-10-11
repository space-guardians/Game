<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Empire;

/**
 * Statut allié / ennemi entre deux empires (§4.10.1), vérifié partout où une action hostile est possible : deux
 * empires alliés ne s'attaquent jamais. Pour l'instant, seul un empire est allié avec lui-même ; les alliances et
 * pactes (#56) étendront cette règle.
 */
final readonly class Allegiances
{
    public function allied(Empire $a, Empire $b): bool
    {
        return $a === $b || (null !== $a->getId() && $a->getId() === $b->getId());
    }
}
