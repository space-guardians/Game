<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\FleetOrder;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Fleet\FleetAction;
use App\Exception\Fleet\InvalidFleetMission;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Service\Economy\PlanetResources;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Envoi d'une flotte en mission (§4.6) : un carnet d'ordres « se déplacer puis agir », exécutés l'un après l'autre,
 * et une cargaison chargée sur la planète de départ. La flotte part aussitôt vers la destination du premier ordre.
 */
final readonly class FleetDispatch
{
    /** Ordres au plus dans un carnet */
    public const int MAX_STEPS = 5;

    public function __construct(
        private FleetMovements $movements,
        private PlanetResources $resources,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<MissionStep> $steps
     *
     * @throws InvalidFleetMission
     */
    public function dispatch(Fleet $fleet, array $steps, int $speedPercent, Resources $cargo): FleetMovement
    {
        if (!\in_array($speedPercent, TravelRules::SPEED_PERCENTS, true)) {
            throw new InvalidFleetMission('Choisissez une vitesse entre 10 et 100 %.');
        }
        if ([] === $steps || \count($steps) > self::MAX_STEPS) {
            throw new InvalidFleetMission(\sprintf('Une mission compte de 1 à %d ordres.', self::MAX_STEPS));
        }
        $colonizations = 0;
        foreach ($steps as $rank => $step) {
            if ($step->action->requiresPlanet() && null === $step->destination->planetId) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : « %s » doit viser une planète.', $rank + 1, $step->action->label()));
            }
            if (FleetAction::Colonize === $step->action) {
                ++$colonizations;
            }
        }
        // Chaque « Coloniser » consomme un colonisateur de la flotte
        if ($colonizations > $this->colonyShips($fleet)) {
            throw new InvalidFleetMission(\sprintf('« Coloniser » consomme un colonisateur : il en faut %d dans la flotte.', $colonizations));
        }

        $planet = $fleet->getPlanet();
        $loading = $cargo->metal + $cargo->crystal + $cargo->deuterium > 0;
        $lock = $this->lockFactory->createLock(null === $planet ? 'fleet-' . $fleet->getId() : ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: 30.0);
        $lock->acquire(true);

        try {
            if (!$fleet->isStationed()) {
                throw new InvalidFleetMission(\sprintf('La flotte « %s » n’est pas stationnée : elle exécute déjà une mission.', $fleet->getName()));
            }
            if ($loading && (null === $planet || !$fleet->isAtHome())) {
                throw new InvalidFleetMission('Une flotte ne charge des ressources que sur une planète de son empire.');
            }
            if ($loading && $cargo->metal + $cargo->crystal + $cargo->deuterium > $fleet->cargo()) {
                throw new InvalidFleetMission(\sprintf('Cargaison trop lourde : la flotte emporte au plus %d.', $fleet->cargo()));
            }

            return $this->entityManager->wrapInTransaction(function () use ($fleet, $steps, $speedPercent, $cargo, $planet, $loading): FleetMovement {
                $now = $this->clock->now();
                if ($loading) {
                    $this->load($fleet, $planet, $cargo);
                }

                $fleet->replaceOrders();
                $this->entityManager->flush();
                foreach ($steps as $rank => $step) {
                    $fleet->addOrder(new FleetOrder($fleet, $rank + 1, SpaceLocation::of($step->destination), $step->action, mb_substr($step->label, 0, 60)));
                }

                $movement = $this->movements->launchNext($fleet, $now, $speedPercent);
                \assert(null !== $movement);

                return $movement;
            });
        } finally {
            $lock->release();
        }
    }

    private function colonyShips(Fleet $fleet): int
    {
        $count = 0;
        foreach ($fleet->getShips() as $ships) {
            if (ShipType::COLONY_SHIP === $ships->getType()->getCode()) {
                $count += $ships->getQuantity();
            }
        }

        return $count;
    }

    private function load(Fleet $fleet, Planet $planet, Resources $cargo): void
    {
        $snapshot = $this->resources->settle($planet);
        if (!$snapshot->amounts->covers($cargo)) {
            throw new InvalidFleetMission('La planète n’a pas les ressources à charger.');
        }
        $planet->storeResources($snapshot->amounts->minus($cargo), $snapshot->at);
        $fleet->load($cargo);
    }
}
