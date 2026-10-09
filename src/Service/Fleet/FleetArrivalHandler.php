<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\ExplorationEventInstance;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\FleetOrder;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Fleet\FleetAction;
use App\Model\Fleet\SpacePosition;
use App\Service\Economy\PlanetResources;
use App\Service\Exploration\Explorations;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Arrivée d'une flotte à la destination de son ordre en cours (§4.6) : elle s'y place, effectue l'action de l'ordre,
 * puis part aussitôt vers le suivant — ou reste stationnée sur place s'il n'y en a plus. Une flotte vidée par la
 * colonisation (colonisateur seul) disparaît. Une panne de carburant l'immobilise en chemin (§4.6.3). Une exploration
 * dont la quête attend le choix du joueur la retient sur place (§4.6.4). Appelé par le résolveur, sous verrou (de la
 * planète visée le cas échéant) et dans une transaction.
 */
final readonly class FleetArrivalHandler implements ScheduledEventHandler
{
    public const string TYPE = 'fleet.arrival';

    /** Écart toléré entre les positions de deux flottes au rendez-vous d'un ravitaillement */
    private const float RENDEZVOUS_TOLERANCE = 1.0;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FleetMovements $movements,
        private PlanetResources $resources,
        private TrajectoryPlanner $planner,
        private Explorations $explorations,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public function handle(ScheduledEvent $event): void
    {
        $movement = $this->entityManager->find(FleetMovement::class, $event->getPayload()['movement'] ?? null);
        if (!$movement instanceof FleetMovement) {
            return;
        }

        $fleet = $movement->getFleet();
        $order = $movement->getOrder();
        $arrival = $movement->getArrivesAt();
        $destination = $order->getDestination();

        if ($movement->isStranding()) {
            $this->strand($movement);

            return;
        }
        $planet = null === $destination->planetId ? null : $this->entityManager->find(Planet::class, $destination->planetId);
        $fleet->arriveAt($destination, $planet);

        $speedPercent = $movement->getSpeedPercent();
        $awaiting = $this->act($fleet, $order, $planet, $arrival, $speedPercent);

        $this->entityManager->remove($movement);
        if ($fleet->isEmpty()) {
            // Plus aucun vaisseau (colonisateur seul consommé, flotte perdue en exploration) : elle disparaît avec ses
            // ordres restants
            $this->entityManager->flush();
            $this->explorations->forget($fleet);
            $this->entityManager->remove($fleet);
            $this->entityManager->flush();

            return;
        }
        if (null !== $awaiting) {
            // Décision attendue : la flotte reste sur place, ses ordres restants reprendront ensuite
            $fleet->station();
            $this->entityManager->flush();

            return;
        }
        $this->entityManager->flush();
        $this->movements->launchNext($fleet, $arrival, $speedPercent);
    }

    /** Panne de carburant : la flotte s'immobilise là où son réservoir s'est vidé ; ses ordres restants attendent */
    private function strand(FleetMovement $movement): void
    {
        $fleet = $movement->getFleet();
        $order = $movement->getOrder();
        $trajectory = $this->planner->plan($movement->getOrigin()->toPosition(), $order->getDestination()->toPosition());
        $point = $trajectory->pointAt($movement->getReach())['position'];
        $fleet->strand(SpaceLocation::of(SpacePosition::deepSpace($movement->getOrigin()->galaxyId, $point)));
        $order->fail(\sprintf('Panne de carburant en route vers %s : la flotte attend un ravitaillement.', $order->getDestinationLabel()), $movement->getArrivesAt());
        $this->entityManager->remove($movement);
        $this->entityManager->flush();
    }

    /** Effectue l'action de l'ordre ; renvoie l'événement d'exploration qui attend le choix du joueur, le cas échéant */
    private function act(Fleet $fleet, FleetOrder $order, ?Planet $planet, \DateTimeImmutable $arrival, int $speedPercent): ?ExplorationEventInstance
    {
        $awaiting = null;
        switch ($order->getAction()) {
            case FleetAction::Transport:
                if (null === $planet || null === $planet->getOwner()) {
                    $order->fail('Plus de planète habitée à cette position : la cargaison reste à bord.', $arrival);

                    return null;
                }
                // Production consolidée jusqu'à l'arrivée, puis déchargement (le stock peut dépasser les dépôts)
                $snapshot = $this->resources->settle($planet, $arrival);
                $planet->storeResources($snapshot->amounts->plus($fleet->unload()), $arrival);
                break;
            case FleetAction::Station:
                break;
            case FleetAction::Refuel:
                $failure = $this->refuel($fleet, $order);
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return null;
                }
                break;
            case FleetAction::Explore:
                $awaiting = $this->explorations->explore($fleet, $order->getDestination(), $order->getDestinationLabel(), $arrival, $speedPercent);
                break;
            case FleetAction::Colonize:
                $failure = $this->colonize($fleet, $planet, $arrival);
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return null;
                }
                break;
        }
        $order->complete($arrival);

        return $awaiting;
    }

    /**
     * Livre le deutérium de la cargaison à la flotte visée, présente sur place (§4.6.3). Renvoie la raison d'un échec,
     * ou null.
     */
    private function refuel(Fleet $fleet, FleetOrder $order): ?string
    {
        $target = null === $order->getTargetFleetId() ? null : $this->entityManager->find(Fleet::class, $order->getTargetFleetId());
        if (!$target instanceof Fleet || $target === $fleet) {
            return 'La flotte à ravitailler n’existe plus.';
        }
        // Allié ou même empire : le statut allié arrive avec les alliances (phase 9)
        if ($target->getEmpire() !== $fleet->getEmpire()) {
            return 'Seules les flottes de l’empire peuvent être ravitaillées.';
        }
        if ($target->getLocation()->toPosition()->global()->distanceTo($fleet->getLocation()->toPosition()->global()) > self::RENDEZVOUS_TOLERANCE) {
            return \sprintf('La flotte « %s » n’est plus à cette position.', $target->getName());
        }
        $delivered = $target->refuel($fleet->getCargo()->deuterium);
        if ($delivered <= 0.0) {
            return 'Rien à livrer : pas de deutérium en cargaison, ou réservoirs déjà pleins.';
        }
        $fleet->takeCargoDeuterium($delivered);

        return null;
    }

    /**
     * Fonde une colonie sur une planète libre : elle rejoint l'empire, reçoit la cargaison, et un colonisateur est
     * consommé. Renvoie la raison d'un échec, ou null.
     */
    private function colonize(Fleet $fleet, ?Planet $planet, \DateTimeImmutable $arrival): ?string
    {
        if (null === $planet) {
            return 'Plus de planète à cette position.';
        }
        if (null !== $planet->getOwner()) {
            return \sprintf('La planète %s est déjà occupée.', $planet);
        }
        $colonyShip = null;
        foreach ($fleet->getShips() as $ships) {
            if (ShipType::COLONY_SHIP === $ships->getType()->getCode()) {
                $colonyShip = $ships->getType();
            }
        }
        if (null === $colonyShip) {
            return 'Plus de colonisateur dans la flotte.';
        }

        $planet->assignTo($fleet->getEmpire());
        // La production de la colonie démarre à sa fondation, avec la cargaison de la flotte pour premier stock
        $planet->storeResources($fleet->unload(), $arrival);
        $fleet->removeShips($colonyShip, 1);

        return null;
    }
}
