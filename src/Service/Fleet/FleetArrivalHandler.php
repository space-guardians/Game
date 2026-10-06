<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\FleetOrder;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Enum\Fleet\FleetAction;
use App\Service\Economy\PlanetResources;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Arrivée d'une flotte à la destination de son ordre en cours (§4.6) : elle s'y place, effectue l'action de l'ordre,
 * puis part aussitôt vers le suivant — ou reste stationnée sur place s'il n'y en a plus. Appelé par le résolveur, sous
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
        }
        $order->complete($arrival);
    }
}
