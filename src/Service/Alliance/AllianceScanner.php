<?php

declare(strict_types=1);

namespace App\Service\Alliance;

use App\Entity\AlliancePlanet;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Enum\Alliance\AllianceBuildingType;
use App\Model\Alliance\ScanContact;
use App\Model\Fleet\SpacePosition;
use App\Repository\AllianceMemberRepository;
use App\Repository\AlliancePlanetRepository;
use App\Repository\FleetMovementRepository;
use App\Repository\FleetRepository;
use App\Service\Fleet\TrajectoryPlanner;
use Psr\Clock\ClockInterface;

/**
 * Scanner d'alliance (§4.10) : une planète d'alliance dotée d'un scanner repère les flottes des autres empires, à
 * l'arrêt ou en vol, dans sa portée ; un départ dont le trajet passe à portée alerte aussitôt les membres.
 */
final readonly class AllianceScanner
{
    public function __construct(
        private ScannerRules $rules,
        private TrajectoryPlanner $planner,
        private AlliancePlanetRepository $alliancePlanets,
        private AllianceMemberRepository $members,
        private FleetRepository $fleets,
        private FleetMovementRepository $movements,
        private ScannerAlerts $alerts,
        private ClockInterface $clock,
    ) {}

    public function range(AlliancePlanet $alliancePlanet): float
    {
        return $alliancePlanet->isBuiltAt($this->clock->now()) ? $this->rules->range($alliancePlanet->buildingLevel(AllianceBuildingType::Scanner)) : 0.0;
    }

    /**
     * Flottes des autres empires à portée, de la plus proche à la plus lointaine.
     *
     * @return list<ScanContact>
     */
    public function contacts(AlliancePlanet $alliancePlanet): array
    {
        $range = $this->range($alliancePlanet);
        if ($range <= 0.0) {
            return [];
        }
        $now = $this->clock->now();
        $center = SpacePosition::planet($alliancePlanet->getPlanet())->global();
        $galaxyId = (int) $alliancePlanet->getPlanet()->getSystem()->getGalaxy()->getId();
        $contacts = [];

        foreach ($this->fleets->findStoppedNear($galaxyId, $center, $range) as $fleet) {
            $distance = $fleet->getLocation()->toPosition()->global()->distanceTo($center);
            if ($distance <= $range && $this->foreign($fleet, $alliancePlanet)) {
                $contacts[] = new ScanContact($fleet->getEmpire()->getName(), $fleet->getName(), $fleet->shipCount(), (int) round($distance), false);
            }
        }
        foreach ($this->movements->findInGalaxy($galaxyId) as $movement) {
            $fleet = $movement->getFleet();
            // En transit par une porte de téléportation : hors de l'espace, invisible
            if ($movement->isTeleport() || !$this->foreign($fleet, $alliancePlanet)) {
                continue;
            }
            $trajectory = $this->planner->plan($movement->getOrigin()->toPosition(), $movement->getOrder()->getDestination()->toPosition());
            $position = $trajectory->pointAt($this->rules->travelled($movement->getDepartedAt(), $movement->getArrivesAt(), $movement->getReach(), $now))['position'];
            $distance = $position->distanceTo($center);
            if ($distance <= $range) {
                $contacts[] = new ScanContact($fleet->getEmpire()->getName(), $fleet->getName(), $fleet->shipCount(), (int) round($distance), true, $movement->getOrder()->getDestinationLabel(), $movement->getArrivesAt());
            }
        }
        usort($contacts, static fn(ScanContact $a, ScanContact $b): int => $a->distance <=> $b->distance);

        return $contacts;
    }

    /**
     * Départ d'une flotte : chaque planète d'alliance dont le scanner couvre une partie du trajet alerte ses membres
     * (publié une fois le départ validé, ScannerAlertNotifier).
     */
    public function watch(FleetMovement $movement): void
    {
        $origin = $movement->getOrigin()->toPosition();
        $scanning = $this->alliancePlanets->findScanningInGalaxy($origin->galaxyId);
        if ([] === $scanning) {
            return;
        }
        $fleet = $movement->getFleet();
        $order = $movement->getOrder();
        $trajectory = $this->planner->plan($origin, $order->getDestination()->toPosition());
        foreach ($scanning as $alliancePlanet) {
            $range = $this->range($alliancePlanet);
            if ($range <= 0.0 || !$this->foreign($fleet, $alliancePlanet)
                || $this->rules->closestApproach($trajectory, SpacePosition::planet($alliancePlanet->getPlanet())->global()) > $range) {
                continue;
            }
            $this->alerts->detected($alliancePlanet, $fleet, $order->getDestinationLabel(), $movement->getArrivesAt());
        }
    }

    /** Flotte d'un empire étranger à l'alliance de la planète */
    private function foreign(Fleet $fleet, AlliancePlanet $alliancePlanet): bool
    {
        return $this->members->findOneByEmpire($fleet->getEmpire())?->getAlliance() !== $alliancePlanet->getAlliance();
    }
}
