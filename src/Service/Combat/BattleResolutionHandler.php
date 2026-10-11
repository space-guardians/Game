<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\BattleParticipant;
use App\Entity\Fleet;
use App\Entity\FleetOrder;
use App\Entity\ScheduledEvent;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Fleet\FleetStatus;
use App\Model\Combat\SideMotion;
use App\Repository\ClassMatchupRepository;
use App\Repository\FleetMovementRepository;
use App\Repository\FleetRepository;
use App\Service\Exploration\Explorations;
use App\Service\Fleet\FleetMovements;
use App\Service\Scheduling\EventScheduler;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

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
        private ClassMatchupRepository $matchups,
        private CombatGroups $groups,
        private CombatEngine $engine,
        private EngagementRules $engagement,
        private FleetMovements $fleetMovements,
        private Explorations $explorations,
        private EventScheduler $scheduler,
        private Randomizer $randomizer,
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
            $side = $this->allegiances->allied($fleet->getEmpire(), $initiator) ? CombatSide::Attacker : CombatSide::Defender;
            $battle->join($fleet, $side, $at)->assignSide($side);
            if (CombatSide::Attacker === $side) {
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

        $result = $this->engine->resolve(
            [...$this->groups->of(CombatSide::Attacker, $attackers), ...$this->groups->of(CombatSide::Defender, $defenders)],
            $this->matchups->matrix(),
            $this->randomizer,
            $this->engagement->orders(SideMotion::ofFleets($attackers), SideMotion::ofFleets($defenders)),
        );
        $battle->resolve($result->winner, \count($result->rounds), [
            CombatSide::Attacker->value => $result->lossesBySide(CombatSide::Attacker),
            CombatSide::Defender->value => $result->lossesBySide(CombatSide::Defender),
        ], $at);

        $survivors = [];
        foreach ($battle->getParticipants() as $participant) {
            $fleet = $participant->getFleet();
            if (null === $fleet || !isset($present[(int) $fleet->getId()])) {
                continue;
            }
            $losses = $result->lossesOfFleet((int) $fleet->getId());
            $participant->recordLosses($losses);
            $this->removeLosses($fleet, $losses);
            if ($fleet->isEmpty()) {
                $this->destroy($fleet, $participant);
            } elseif (null !== $this->battleOrder($fleet)) {
                $survivors[] = $participant;
            }
        }
        $this->entityManager->flush();

        // Les flottes venues combattre (de part et d'autre) terminent leur ordre, puis enchaînent
        foreach ($survivors as $participant) {
            $fleet = $participant->getFleet();
            \assert(null !== $fleet);
            $this->battleOrder($fleet)?->complete($at);
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

    /** @param array<string, int> $losses par code de type */
    private function removeLosses(Fleet $fleet, array $losses): void
    {
        foreach ($fleet->getShips()->toArray() as $ships) {
            $lost = min($losses[$ships->getType()->getCode()] ?? 0, $ships->getQuantity());
            if ($lost > 0) {
                $fleet->removeShips($ships->getType(), $lost);
            }
        }
    }

    /** Flotte anéantie : elle disparaît ; sa participation et ses événements d'exploration restent */
    private function destroy(Fleet $fleet, BattleParticipant $participant): void
    {
        $participant->releaseFleet();
        $this->entityManager->flush();
        $this->explorations->forget($fleet);
        $this->entityManager->remove($fleet);
    }
}
