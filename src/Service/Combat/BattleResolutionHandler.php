<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\Fleet;
use App\Entity\FleetOrder;
use App\Entity\ScheduledEvent;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Fleet\FleetStatus;
use App\Repository\FleetMovementRepository;
use App\Repository\FleetRepository;
use App\Service\Fleet\FleetMovements;
use App\Service\Scheduling\EventScheduler;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résolution d'une bataille programmée à son heure (§4.7) : toutes les flottes présentes sur place combattent — venues
 * en « Rejoindre une bataille » ou à l'arrêt au même lieu. Pas de choix de camp : les alliés de l'empire qui a lancé
 * l'attaque (Allegiances) forment le camp assaillant, toutes les autres le camp défenseur ; les flottes d'un camp sont
 * agrégées avant le combat. Les pertes sont retirées des flottes (une flotte anéantie disparaît), puis les flottes
 * venues combattre reprennent leurs ordres. Une flotte encore attendue à cette heure (arrivée pas encore traitée)
 * fait patienter la résolution ; sans assaillant sur place, la bataille est annulée.
 */
final readonly class BattleResolutionHandler implements ScheduledEventHandler
{
    public const string TYPE = 'battle.resolution';

    /** Délai avant de retenter la résolution quand l'arrivée d'un assaillant n'a pas encore été traitée */
    private const int WAIT_FOR_ARRIVAL_SECONDS = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FleetRepository $fleets,
        private FleetMovementRepository $movements,
        private BattleFight $fight,
        private FleetMovements $fleetMovements,
        private EventScheduler $scheduler,
        private Allegiances $allegiances,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public function handle(ScheduledEvent $event): void
    {
        $battle = $this->entityManager->find(Battle::class, $event->getPayload()['battle'] ?? null);
        if (!$battle instanceof Battle || !$battle->isScheduled()) {
            return;
        }
        $at = $battle->getResolvesAt();
        $initiator = $battle->getInitiator();
        if (null === $initiator) {
            $battle->cancel($at);

            return;
        }

        // Flottes sur place : celles venues combattre (participants), puis les flottes à l'arrêt au même lieu
        $present = [];
        foreach ($battle->getParticipants() as $participant) {
            $fleet = $participant->getFleet();
            if (null === $fleet) {
                continue;
            }
            if ($this->stillArriving($fleet, $battle)) {
                // Arrivée due mais pas encore traitée : la bataille attend ce combattant
                $this->scheduler->schedule(self::TYPE, $event->getDueAt()->modify(\sprintf('+%d seconds', self::WAIT_FOR_ARRIVAL_SECONDS)), $event->getPlanet(), ['battle' => $battle->getId()]);

                return;
            }
            if ($this->onSite($fleet, $battle)) {
                $present[(int) $fleet->getId()] = $fleet;
            }
        }
        foreach ($this->fleets->findStoppedInGalaxy($battle->getLocation()->galaxyId) as $fleet) {
            if ($this->onSite($fleet, $battle)) {
                $present[(int) $fleet->getId()] ??= $fleet;
            }
        }

        // Pas de choix de camp : alliés de l'empire qui a lancé l'attaque d'un côté, tous les autres de l'autre (§4.7)
        $attackers = [];
        $defenders = [];
        foreach ($present as $fleet) {
            if ($this->allegiances->allied($fleet->getEmpire(), $initiator)) {
                $attackers[] = $fleet;
            } else {
                $defenders[] = $fleet;
            }
        }
        if ([] === $attackers) {
            $battle->cancel($at);
            $this->entityManager->flush();

            return;
        }

        $this->fight->fight($battle, $attackers, $defenders, $at);

        // Les flottes venues combattre (de part et d'autre) et encore debout terminent leur ordre, puis enchaînent
        foreach ($battle->getParticipants() as $participant) {
            $fleet = $participant->getFleet();
            $order = null === $fleet ? null : $this->battleOrder($fleet);
            if (null === $fleet || null === $order || !isset($present[(int) $fleet->getId()])) {
                continue;
            }
            $order->complete($at);
            $this->entityManager->flush();
            $this->fleetMovements->launchNext($fleet, $at, $participant->getSpeedPercent());
        }
    }

    /** Ordre « Rejoindre une bataille » en cours d'une flotte venue combattre */
    private function battleOrder(Fleet $fleet): ?FleetOrder
    {
        foreach ($fleet->getOrders() as $order) {
            if (FleetAction::JoinBattle === $order->getAction() && FleetOrderStatus::InProgress === $order->getStatus()) {
                return $order;
            }
        }

        return null;
    }

    /** Assaillant dont l'arrivée sur les lieux tombe avant la résolution, mais n'a pas encore été traitée */
    private function stillArriving(Fleet $fleet, Battle $battle): bool
    {
        if (FleetStatus::InFlight !== $fleet->getStatus()) {
            return false;
        }
        $movement = $this->movements->findActiveFor($fleet);

        return null !== $movement
            && !$movement->isStranding()
            && $movement->getArrivesAt() <= $battle->getResolvesAt()
            && Battles::samePlace($movement->getOrder()->getDestination(), $battle->getLocation());
    }

    private function onSite(Fleet $fleet, Battle $battle): bool
    {
        return FleetStatus::InFlight !== $fleet->getStatus() && Battles::samePlace($fleet->getLocation(), $battle->getLocation());
    }
}
