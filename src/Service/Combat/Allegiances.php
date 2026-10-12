<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Empire;
use App\Repository\AllianceMemberRepository;
use App\Repository\AlliancePactRepository;
use Psr\Clock\ClockInterface;

/**
 * Statut allié / ennemi entre deux empires (§4.10.1), vérifié partout où une action hostile est possible (bataille,
 * pillage, interception…) : deux empires alliés ne s'attaquent jamais, se renforcent, se ravitaillent et défendent
 * ensemble. Sont alliés un empire et lui-même, les membres d'une même alliance, et les deux parties d'un pacte de
 * non-agression en vigueur.
 */
final readonly class Allegiances
{
    public function __construct(
        private AllianceMemberRepository $members,
        private AlliancePactRepository $pacts,
        private ClockInterface $clock,
    ) {}

    public function allied(Empire $a, Empire $b): bool
    {
        if ($a === $b || (null !== $a->getId() && $a->getId() === $b->getId())) {
            return true;
        }
        $alliance = $this->members->findOneByEmpire($a)?->getAlliance();
        if (null !== $alliance && $this->members->findOneByEmpire($b)?->getAlliance() === $alliance) {
            return true;
        }

        return $this->pacts->activeBetween($a, $b, $this->clock->now());
    }

    /**
     * Alliés d'un empire, lui excepté : membres de son alliance et partenaires de pactes en vigueur.
     *
     * @return list<Empire>
     */
    public function alliesOf(Empire $empire): array
    {
        $allies = [];
        foreach ($this->members->findOneByEmpire($empire)?->getAlliance()->getMembers() ?? [] as $member) {
            $allies[(int) $member->getEmpire()->getId()] = $member->getEmpire();
        }
        foreach ($this->pacts->findPartnersOf($empire, $this->clock->now()) as $partner) {
            $allies[(int) $partner->getId()] = $partner;
        }
        unset($allies[(int) $empire->getId()]);

        return array_values($allies);
    }
}
