<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\FleetOrder;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Combat\InterceptionOutcome;
use App\Enum\Fleet\FleetAction;
use App\Enum\Messaging\MessageCategory;
use App\Model\Economy\Resources;
use App\Model\Fleet\SpacePosition;
use App\Repository\BattleRepository;
use App\Service\Alliance\TeleportGates;
use App\Service\Combat\Allegiances;
use App\Service\Combat\DebrisFields;
use App\Service\Combat\Interceptions;
use App\Service\Economy\PlanetResources;
use App\Service\Espionage\Espionage;
use App\Service\Exploration\Explorations;
use App\Service\Market\Market;
use App\Service\Messaging\GameReports;
use App\Service\Scheduling\ScheduledEventHandler;
use App\Service\Universe\ColonyLimit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Arrivée d'une flotte à la destination de son ordre en cours (§4.6) : elle s'y place, effectue l'action de l'ordre,
 * puis part aussitôt vers le suivant — ou reste stationnée sur place s'il n'y en a plus. Une flotte vidée par la
 * colonisation (colonisateur seul) disparaît. Une panne de carburant l'immobilise en chemin (§4.6.3). Une exploration
 * dont la quête attend le choix du joueur (§4.6.4), ou une bataille programmée (§4.7), la retient sur place. Entrant
 * dans un système gardé par des flottes hostiles, elle est d'abord interceptée (§4.6.2). Appelé par le résolveur, sous verrou (de la
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
        private ColonyLimit $colonyLimit,
        private BattleRepository $battles,
        private Interceptions $interceptions,
        private DebrisFields $debris,
        private Espionage $espionage,
        private Allegiances $allegiances,
        private TeleportGates $teleports,
        private GameReports $reports,
        private Market $market,
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
        if ($movement->isTeleport()) {
            $this->leaveGate($movement);

            return;
        }
        $origin = $movement->getOrigin();
        $speedPercent = $movement->getSpeedPercent();
        $this->entityManager->remove($movement);
        $this->entityManager->flush();

        // Entrée dans un système gardé par des flottes hostiles stationnées au niveau du système (§4.6.2)
        switch ($this->intercept($fleet, $order, $origin, $arrival)) {
            case InterceptionOutcome::Destroyed:
                return;
            case InterceptionOutcome::Stopped:
                $order->fail(\sprintf('Interceptée à l’entrée du système par des flottes hostiles : %s n’a pas été atteint.', $order->getDestinationLabel()), $arrival);
                $fleet->station();
                $this->entityManager->flush();

                return;
            default:
                break;
        }

        $planet = null === $destination->planetId ? null : $this->entityManager->find(Planet::class, $destination->planetId);
        $fleet->arriveAt($destination, $planet);

        $hold = $this->act($fleet, $order, $planet, $arrival, $speedPercent);

        if ($fleet->isEmpty()) {
            // Plus aucun vaisseau (colonisateur seul consommé, flotte perdue en exploration, sondes abattues) : elle
            // disparaît avec ses ordres restants — déjà supprimée si elle a péri au combat
            $this->entityManager->flush();
            if ($this->entityManager->contains($fleet)) {
                $this->explorations->forget($fleet);
                $this->entityManager->remove($fleet);
                $this->entityManager->flush();
            }

            return;
        }
        if ($hold) {
            // Décision d'exploration ou bataille attendue : la flotte reste sur place, ses ordres restants reprendront
            // ensuite ; entrée dans une porte de téléportation : elle est déjà repartie, en transit
            if (FleetAction::Teleport !== $order->getAction()) {
                $fleet->station();
            }
            $this->entityManager->flush();

            return;
        }
        $this->entityManager->flush();
        $this->movements->launchNext($fleet, $arrival, $speedPercent);
    }

    /**
     * Interception à l'entrée du système de destination. Une attaque qui vise les flottes du système lui-même y livre
     * directement sa bataille programmée : pas d'interception préalable.
     */
    private function intercept(Fleet $fleet, FleetOrder $order, SpaceLocation $origin, \DateTimeImmutable $arrival): InterceptionOutcome
    {
        $destination = $order->getDestination();
        if (FleetAction::JoinBattle === $order->getAction() && null === $destination->planetId && null === $destination->localX) {
            return InterceptionOutcome::None;
        }

        return $this->interceptions->enter($fleet, $origin, $destination, $arrival);
    }

    /**
     * Sortie d'une porte de téléportation (§4.10) : la flotte apparaît sur la planète de la porte reliée, sans
     * interception (elle n'a pas franchi la lisière du système), l'ordre est accompli et les suivants reprennent.
     */
    private function leaveGate(FleetMovement $movement): void
    {
        $fleet = $movement->getFleet();
        $order = $movement->getOrder();
        $arrival = $movement->getArrivesAt();
        $speedPercent = $movement->getSpeedPercent();
        $exit = $movement->getTeleportExit();
        \assert(null !== $exit);
        $this->entityManager->remove($movement);
        $fleet->arriveAt(SpaceLocation::of(SpacePosition::planet($exit)), $exit);
        $order->complete($arrival);
        $this->entityManager->flush();
        $this->movements->launchNext($fleet, $arrival, $speedPercent);
    }

    /**
     * Rapport automatique de livraison (§4.12.2) : à l'empire de la flotte, et au propriétaire de la planète s'il est un
     * autre empire.
     */
    private function reportTransport(Fleet $fleet, Planet $planet, Resources $delivered): void
    {
        $amounts = \sprintf('%s métal, %s cristal, %s deutérium', number_format($delivered->metal, 0, ',', ' '), number_format($delivered->crystal, 0, ',', ' '), number_format($delivered->deuterium, 0, ',', ' '));
        $this->reports->report(
            $fleet->getEmpire(),
            MessageCategory::Transport,
            \sprintf('Transport livré : %s', $planet),
            \sprintf('La flotte « %s » a livré %s en %s.', $fleet->getName(), $amounts, $planet),
        );
        $owner = $planet->getOwner();
        if (null !== $owner && $owner !== $fleet->getEmpire()) {
            $this->reports->report(
                $owner,
                MessageCategory::Transport,
                \sprintf('Livraison reçue : %s', $planet),
                \sprintf('%s vous a livré %s en %s.', $fleet->getEmpire()->getName(), $amounts, $planet),
            );
        }
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

    /**
     * Effectue l'action de l'ordre ; renvoie vrai si la flotte doit attendre sur place (décision d'exploration du
     * joueur, bataille programmée) avant de reprendre ses ordres.
     */
    private function act(Fleet $fleet, FleetOrder $order, ?Planet $planet, \DateTimeImmutable $arrival, int $speedPercent): bool
    {
        $hold = false;
        switch ($order->getAction()) {
            case FleetAction::Transport:
                // Planète d'un empire, ou planète d'alliance (contributions des membres)
                if (null === $planet || !$planet->isClaimed()) {
                    $order->fail('Plus de planète habitée à cette position : la cargaison reste à bord.', $arrival);

                    return false;
                }
                // Production consolidée jusqu'à l'arrivée, puis déchargement (le stock peut dépasser les dépôts)
                $snapshot = $this->resources->settle($planet, $arrival);
                $delivered = $fleet->unload();
                $planet->storeResources($snapshot->amounts->plus($delivered), $arrival);
                $this->reportTransport($fleet, $planet, $delivered);
                break;
            case FleetAction::Station:
                break;
            case FleetAction::Refuel:
                $failure = $this->refuel($fleet, $order);
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return false;
                }
                break;
            case FleetAction::Spy:
                $failure = $this->espionage->spy($fleet, $order->getDestination(), $order->getDestinationLabel(), $planet, $arrival);
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return false;
                }
                break;
            case FleetAction::Recycle:
                $failure = $this->debris->recycle($fleet, $order->getDestination(), $arrival);
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return false;
                }
                break;
            case FleetAction::Explore:
                $hold = null !== $this->explorations->explore($fleet, $order->getDestination(), $order->getDestinationLabel(), $arrival, $speedPercent);
                break;
            case FleetAction::JoinBattle:
                // La bataille se résout à son heure : l'ordre reste en cours jusque-là (BattleResolutionHandler)
                $battle = $this->battles->findScheduledFor($fleet);
                if (null === $battle || $battle->getResolvesAt() < $arrival) {
                    $order->fail('La bataille a déjà eu lieu.', $arrival);

                    return false;
                }

                return true;
            case FleetAction::Teleport:
                // Entrée par la porte : la flotte repart aussitôt en transit, l'ordre s'accomplit à la sortie
                $failure = null === $planet ? 'Plus de planète à cette position.' : $this->teleports->enter($fleet, $order, $planet, $arrival, $speedPercent);
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return false;
                }

                return true;
            case FleetAction::MarketDeliver:
            case FleetAction::MarketCollect:
                // Marché (§4.15) : livraison des transactions en cours, ou retrait de ce que le marchand garde pour l'empire
                $failure = FleetAction::MarketDeliver === $order->getAction()
                    ? $this->market->deliver($fleet, $order->getDestination(), $arrival)
                    : $this->market->collect($fleet, $order->getDestination());
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return false;
                }
                break;
            case FleetAction::Colonize:
                $failure = $this->colonize($fleet, $planet, $arrival);
                if (null !== $failure) {
                    $order->fail($failure, $arrival);

                    return false;
                }
                break;
        }
        $order->complete($arrival);

        return $hold;
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
        // Même empire ou allié (alliance, pacte en vigueur), statut vérifié à l'arrivée
        if (!$this->allegiances->allied($target->getEmpire(), $fleet->getEmpire())) {
            return 'Seules les flottes de l’empire ou d’un allié peuvent être ravitaillées.';
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
     * Fonde une colonie sur une planète libre, dans la limite des emplacements de l'empire (§4.1) : elle rejoint
     * l'empire, reçoit la cargaison, et un colonisateur est consommé. Renvoie la raison d'un échec, ou null.
     */
    private function colonize(Fleet $fleet, ?Planet $planet, \DateTimeImmutable $arrival): ?string
    {
        if (null === $planet) {
            return 'Plus de planète à cette position.';
        }
        if ($planet->isClaimed()) {
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
        $refusal = $this->colonyLimit->slots($fleet->getEmpire())->refusal();
        if (null !== $refusal) {
            return $refusal;
        }

        $planet->assignTo($fleet->getEmpire());
        // La production de la colonie démarre à sa fondation, avec la cargaison de la flotte pour premier stock
        $planet->storeResources($fleet->unload(), $arrival);
        $fleet->removeShips($colonyShip, 1);

        return null;
    }
}
