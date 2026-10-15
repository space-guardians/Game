<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Enum\Account\EmpireKind;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetStatus;
use App\Enum\Pve\NpcCategory;
use App\Exception\Fleet\InvalidFleetMission;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetRepository;
use App\Service\Fleet\FleetDispatch;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Raids des civilisations non-joueuses mineures (§4.14) : une civilisation dont le profil autorise les raids, et dont
 * l'intervalle est écoulé, envoie sa flotte stationnée sur la planète de joueur la plus proche dans sa portée, avec le
 * même moteur que les joueurs (`FleetDispatch` : « Rejoindre une bataille », puis retour à sa planète mère). Une
 * civilisation « boss » ne lance jamais de raid.
 */
final readonly class NpcRaids
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FleetRepository $fleets,
        private FleetDispatch $dispatch,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /** Lance les raids dus ; renvoie leur nombre */
    public function launchDue(): int
    {
        $now = $this->clock->now();
        $launched = 0;
        /** @var list<Empire> $npcs */
        $npcs = $this->entityManager->createQueryBuilder()
            ->select('e', 'profile')
            ->from(Empire::class, 'e')
            ->join('e.npcProfile', 'profile')
            ->where('e.kind = :npc')
            ->andWhere('profile.category = :minor')
            ->andWhere('profile.raidsEnabled = true')
            ->setParameter('npc', EmpireKind::Npc)
            ->setParameter('minor', NpcCategory::Minor)
            ->getQuery()
            ->getResult();
        foreach ($npcs as $npc) {
            $profile = $npc->getNpcProfile();
            \assert(null !== $profile);
            $last = $npc->getNpcLastRaidAt();
            if (null !== $last && $last->modify(\sprintf('+%d hours', $profile->getRaidIntervalHours())) > $now) {
                continue;
            }
            if ($this->raid($npc, $profile->getRaidRange())) {
                $npc->npcRaided($now);
                $this->entityManager->flush();
                ++$launched;
            }
        }

        return $launched;
    }

    private function raid(Empire $npc, float $range): bool
    {
        $home = $npc->getHomePlanet();
        $target = $this->nearestPlayerPlanet($home, $range);
        $fleet = $this->stationedFleet($npc);
        if (null === $target || null === $fleet) {
            return false;
        }
        try {
            $this->dispatch->dispatch($fleet, [
                new MissionStep(SpacePosition::planet($target), FleetAction::JoinBattle, (string) $target->getAddress()),
                new MissionStep(SpacePosition::planet($home), FleetAction::Station, (string) $home->getAddress()),
            ], 100, new Resources());
        } catch (InvalidFleetMission $refusal) {
            $this->logger->info('Raid de la civilisation « {npc} » impossible : {reason}', ['npc' => $npc->getName(), 'reason' => $refusal->getMessage()]);

            return false;
        }

        return true;
    }

    /** Planète d'un joueur la plus proche de la planète mère de la civilisation, dans sa galaxie et sa portée */
    private function nearestPlayerPlanet(Planet $home, float $range): ?Planet
    {
        /** @var list<Planet> $planets */
        $planets = $this->entityManager->createQueryBuilder()
            ->select('p', 's')
            ->from(Planet::class, 'p')
            ->join('p.system', 's')
            ->join('p.owner', 'owner')
            ->where('s.galaxy = :galaxy')
            ->andWhere('owner.kind = :player')
            ->setParameter('galaxy', $home->getSystem()->getGalaxy())
            ->setParameter('player', EmpireKind::Player)
            ->getQuery()
            ->getResult();
        $origin = $home->getSystem()->getPosition();
        $nearest = null;
        $best = $range;
        foreach ($planets as $planet) {
            $distance = $planet->getSystem()->getPosition()->distanceTo($origin);
            if ($distance <= $best) {
                [$nearest, $best] = [$planet, $distance];
            }
        }

        return $nearest;
    }

    private function stationedFleet(Empire $npc): ?Fleet
    {
        foreach ($this->fleets->findBy(['empire' => $npc], ['id' => 'ASC']) as $fleet) {
            if (FleetStatus::Stationed === $fleet->getStatus() && $fleet->isAtHome()) {
                return $fleet;
            }
        }

        return null;
    }
}
