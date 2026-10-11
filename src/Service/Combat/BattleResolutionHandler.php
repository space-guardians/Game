<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\BattleParticipant;
use App\Entity\Fleet;
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
 * Résolution d'une bataille programmée à son heure (§4.7) : les assaillants présents sur place affrontent les flottes
 * à l'arrêt des autres empires au même lieu (hostiles en attendant le statut allié, #56), par le moteur de combat.
 * Les pertes sont retirées des flottes (une flotte anéantie disparaît), puis les assaillants survivants reprennent
 * leurs ordres. Un assaillant encore attendu à cette heure (arrivée pas encore traitée) fait patienter la résolution.
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

        $attackers = [];
        foreach ($battle->getParticipants() as $participant) {
            $fleet = $participant->getFleet();
            if (CombatSide::Attacker !== $participant->getSide() || null === $fleet) {
                continue;
            }
            if ($this->stillArriving($fleet, $battle)) {
                // Arrivée due mais pas encore traitée : la bataille attend son assaillant
                $this->scheduler->schedule(self::TYPE, $event->getDueAt()->modify(\sprintf('+%d seconds', self::WAIT_FOR_ARRIVAL_SECONDS)), $event->getPlanet(), ['battle' => $battle->getId()]);

                return;
            }
            if ($this->onSite($fleet, $battle)) {
                $attackers[] = $fleet;
            }
        }
        if ([] === $attackers) {
            $battle->cancel($at);
            $this->entityManager->flush();

            return;
        }

        $attackingEmpires = array_map(static fn(Fleet $fleet): ?int => $fleet->getEmpire()->getId(), $attackers);
        $defenders = array_values(array_filter(
            $this->fleets->findStoppedInGalaxy($battle->getLocation()->galaxyId),
            fn(Fleet $fleet): bool => !\in_array($fleet->getEmpire()->getId(), $attackingEmpires, true) && $this->onSite($fleet, $battle),
        ));
        foreach ($defenders as $fleet) {
            $battle->join($fleet, CombatSide::Defender, $at);
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
            if (null === $fleet || (!\in_array($fleet, $attackers, true) && !\in_array($fleet, $defenders, true))) {
                continue;
            }
            $losses = $result->lossesOfFleet((int) $fleet->getId());
            $participant->recordLosses($losses);
            $this->removeLosses($fleet, $losses);
            if ($fleet->isEmpty()) {
                $this->destroy($fleet, $participant);
            } elseif (CombatSide::Attacker === $participant->getSide()) {
                $survivors[] = $participant;
            }
        }
        $this->entityManager->flush();

        // Les assaillants survivants terminent leur ordre d'attaque, puis enchaînent
        foreach ($survivors as $participant) {
            $fleet = $participant->getFleet();
            \assert(null !== $fleet);
            foreach ($fleet->getOrders() as $order) {
                if (FleetAction::JoinBattle === $order->getAction() && FleetOrderStatus::InProgress === $order->getStatus()) {
                    $order->complete($at);
                }
            }
            $this->entityManager->flush();
            $this->fleetMovements->launchNext($fleet, $at, $participant->getSpeedPercent());
        }
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
