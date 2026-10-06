<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\FleetOrder;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Enum\Fleet\FleetAction;
use App\Service\Economy\PlanetResources;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Arrivée d'une flotte à la destination de son ordre en cours (§4.6) : elle s'y place, effectue l'action de l'ordre,
 * puis part aussitôt vers le suivant — ou reste stationnée sur place s'il n'y en a plus. Une flotte vidée par la
 * colonisation (colonisateur seul) disparaît. Appelé par le résolveur, sous
 * verrou (de la planète visée le cas échéant) et dans une transaction.
 */
final readonly class FleetArrivalHandler implements ScheduledEventHandler
{
    public const string TYPE = 'fleet.arrival';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FleetMovements $movements,
        private PlanetResources $resources,
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
        $planet = null === $destination->planetId ? null : $this->entityManager->find(Planet::class, $destination->planetId);
        $fleet->arriveAt($destination, $planet);

        $this->act($fleet, $order, $planet, $arrival);

        $speedPercent = $movement->getSpeedPercent();
        $this->entityManager->remove($movement);
        if ($fleet->isEmpty()) {
            // Plus aucun vaisseau (colonisateur seul, consommé) : la flotte disparaît avec ses ordres restants
            $this->entityManager->remove($fleet);
            $this->entityManager->flush();

            return;
        }
        $this->entityManager->flush();
        $this->movements->launchNext($fleet, $arrival, $speedPercent);
    }

    private function act(Fleet $fleet, FleetOrder $order, ?Planet $planet, \DateTimeImmutable $arrival): void
    {
        switch ($order->getAction()) {
            case FleetAction::Transport:
                if (null === $planet || null === $planet->getOwner()) {
                    $order->fail('Plus de planète habitée à cette position : la cargaison reste à bord.', $arrival);

                    return;
                }
                // Production consolidée jusqu'à l'arrivée, puis déchargement (le stock peut dépasser les dépôts)
                $snapshot = $this->resources->settle($planet, $arrival);
                $planet->storeResources($snapshot->amounts->plus($fleet->unload()), $arrival);
                break;
            case FleetAction::Station:
                break;
            case FleetAction::Colonize:
                $failure = $this->colonize($fleet, $planet, $arrival);
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return;
                }
                break;
        }
        $order->complete($arrival);
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
