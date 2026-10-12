<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Empire;
use App\Repository\AllianceMemberRepository;

/**
 * Qui lit les rapports de combat de qui (§4.7) : chaque empire lit ceux de ses batailles, et, si son alliance partage
 * ses rapports de combat (paramètre de l'alliance), ceux de tous les membres de l'alliance.
 */
final readonly class CombatReportReaders
{
    public function __construct(
        private AllianceMemberRepository $members,
    ) {}

    /**
     * Empires dont un lecteur voit les rapports : lui d'abord, puis les membres de son alliance si elle partage.
     *
     * @return list<Empire>
     */
    public function sourcesFor(Empire $reader): array
    {
        $empires = [(int) $reader->getId() => $reader];
        $alliance = $this->members->findOneByEmpire($reader)?->getAlliance();
        if (null !== $alliance && $alliance->sharesCombatReports()) {
            foreach ($alliance->getMembers() as $member) {
                $empires[(int) $member->getEmpire()->getId()] ??= $member->getEmpire();
            }
        }

        return array_values($empires);
    }

    /**
     * Empires qui reçoivent le rapport d'une bataille où ces empires étaient engagés : eux, et les membres de leurs
     * alliances qui partagent leurs rapports.
     *
     * @param list<Empire> $participants
     *
     * @return list<Empire>
     */
    public function recipientsOf(array $participants): array
    {
        $recipients = [];
        foreach ($participants as $participant) {
            $recipients[(int) $participant->getId()] = $participant;
            $alliance = $this->members->findOneByEmpire($participant)?->getAlliance();
            if (null !== $alliance && $alliance->sharesCombatReports()) {
                foreach ($alliance->getMembers() as $member) {
                    $recipients[(int) $member->getEmpire()->getId()] ??= $member->getEmpire();
                }
            }
        }

        return array_values($recipients);
    }
}
