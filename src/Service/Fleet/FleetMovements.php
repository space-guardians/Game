<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\Planet;
use App\Service\Scheduling\EventScheduler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enchaînement des ordres d'une flotte (§4.6) : lance le déplacement vers la destination du prochain ordre à venir,
 * dont l'arrivée est planifiée comme événement de jeu (FleetArrivalHandler). Sans ordre à venir, la flotte reste
 * stationnée là où elle est : il n'y a pas de retour implicite.
 */
final readonly class FleetMovements
{
    public function __construct(
        private FleetTravel $travel,
        private EventScheduler $scheduler,
        private EntityManagerInterface $entityManager,
    ) {}

    /** Lance l'ordre suivant à l'heure donnée (fin du précédent, ou départ de la mission) ; null s'il n'y en a plus */
    public function launchNext(Fleet $fleet, \DateTimeImmutable $departure, int $speedPercent): ?FleetMovement
    {
        $order = $fleet->getNextPendingOrder();
        if (null === $order) {
            $fleet->station();

            return null;
        }

        $origin = $fleet->getLocation();
        $destination = $order->getDestination();
        $plan = $this->travel->plan($fleet, $destination->toPosition(), $speedPercent);
        $movement = new FleetMovement($fleet, $order, $origin, $speedPercent, $departure, $plan->arrivalFrom($departure));
        $order->start();
        $fleet->depart();
        $this->entityManager->persist($movement);
        $this->entityManager->flush();

        // Planète visée : sous son verrou à l'arrivée (déchargement, comme une fin de construction)
        $planet = null === $destination->planetId ? null : $this->entityManager->find(Planet::class, $destination->planetId);
        $movement->attachEvent($this->scheduler->schedule(FleetArrivalHandler::TYPE, $movement->getArrivesAt(), $planet, [
            'movement' => $movement->getId(),
            'fleet' => $fleet->getId(),
            'order' => $order->getId(),
        ]));
        $this->entityManager->flush();

        return $movement;
    }
}
