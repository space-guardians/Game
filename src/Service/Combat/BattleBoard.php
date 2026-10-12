<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Enum\Fleet\FleetStatus;
use App\Repository\BattleRepository;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use App\Service\Fleet\FleetTravel;

/**
 * Batailles programmées qu'un empire connaît (§4.7) : celles où il a déjà une flotte engagée, ou qui se tiennent là où
 * stationne l'une de ses flottes ou sur l'une de ses planètes. Il peut y envoyer des renforts (« Rejoindre une
 * bataille »), à condition d'arriver avant l'heure de résolution. La détection plus large (§4.12, scanner) viendra
 * avec ses phases.
 */
final readonly class BattleBoard
{
    public function __construct(
        private BattleRepository $battles,
        private FleetRepository $fleets,
        private FleetTravel $travel,
        private PlanetRepository $planets,
        private Allegiances $allegiances,
    ) {}

    /** @return list<Battle> par heure de résolution */
    public function visibleTo(Empire $empire): array
    {
        $fleets = $this->fleets->findOwnedBy($empire);
        $visible = [];
        foreach ($this->battles->findScheduled() as $battle) {
            if ($this->concerns($battle, $empire, $fleets)) {
                $visible[] = $battle;
            }
        }

        return $visible;
    }

    public function isVisibleTo(Battle $battle, Empire $empire): bool
    {
        return $battle->isScheduled() && $this->concerns($battle, $empire, $this->fleets->findOwnedBy($empire));
    }

    /** La flotte arriverait-elle sur les lieux avant l'heure de la bataille, partant maintenant à cette vitesse ? */
    public function arrivesInTime(Fleet $fleet, Battle $battle, int $speedPercent, \DateTimeImmutable $now): bool
    {
        return $this->travel->plan($fleet, $battle->getLocation()->toPosition(), $speedPercent)->arrivalFrom($now) <= $battle->getResolvesAt();
    }

    /** @param list<Fleet> $fleets flottes de l'empire */
    private function concerns(Battle $battle, Empire $empire, array $fleets): bool
    {
        foreach ($battle->getParticipants() as $participant) {
            if ($participant->getEmpire() === $empire) {
                return true;
            }
        }
        foreach ($fleets as $fleet) {
            if (FleetStatus::InFlight !== $fleet->getStatus() && Battles::samePlace($fleet->getLocation(), $battle->getLocation())) {
                return true;
            }
        }
        $planetId = $battle->getLocation()->planetId;

        $planet = null === $planetId ? null : $this->planets->find($planetId);

        // Planète de l'empire, d'un allié ou de son alliance
        return null !== $planet && $this->allegiances->planetAllied($planet, $empire);
    }
}
