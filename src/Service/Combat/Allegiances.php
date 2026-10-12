<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Empire;
use App\Repository\AllianceMemberRepository;

/**
 * Statut allié / ennemi entre deux empires (§4.10.1), vérifié partout où une action hostile est possible : deux
 * empires alliés ne s'attaquent jamais, se renforcent et défendent ensemble. Sont alliés un empire et lui-même, et les
 * membres d'une même alliance ; les pactes de non-agression (#56) étendront cette règle.
 */
final readonly class Allegiances
{
    public function __construct(
        private AllianceMemberRepository $members,
    ) {}

    public function allied(Empire $a, Empire $b): bool
    {
        if ($a === $b || (null !== $a->getId() && $a->getId() === $b->getId())) {
            return true;
        }
        $alliance = $this->members->findOneByEmpire($a)?->getAlliance();

        return null !== $alliance && $this->members->findOneByEmpire($b)?->getAlliance() === $alliance;
    }
}
