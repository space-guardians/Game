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
 * une cargaison et du carburant (§4.6.3) chargés sur la planète de départ. La flotte part aussitôt vers la destination
 * du premier ordre ; si ses réservoirs ne suffisent pas, elle tombera en panne en chemin.
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
     * @param float             $fuel  deutérium à mettre dans les réservoirs, pris sur la planète de départ
     *
     * @throws InvalidFleetMission
     */
    public function dispatch(Fleet $fleet, array $steps, int $speedPercent, Resources $cargo, float $fuel = 0.0): FleetMovement
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
            if ($step->action->forbidsPlanet() && null !== $step->destination->planetId) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : « %s » ne peut pas viser une planète : visez un système (sans position).', $rank + 1, $step->action->label()));
            }
            if (FleetAction::Colonize === $step->action) {
                ++$colonizations;
            }
            if (FleetAction::Refuel === $step->action) {
                $this->checkRefuelTarget($fleet, $step, $rank + 1);
            }
        }
        // Chaque « Coloniser » consomme un colonisateur de la flotte
        if ($colonizations > $this->colonyShips($fleet)) {
            throw new InvalidFleetMission(\sprintf('« Coloniser » consomme un colonisateur : il en faut %d dans la flotte.', $colonizations));
        }

        $planet = $fleet->getPlanet();
        $loading = $cargo->metal + $cargo->crystal + $cargo->deuterium > 0 || $fuel > 0;
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
            if ($fuel < 0 || $fleet->getFuel() + $fuel > $fleet->tankCapacity() + 1e-6) {
                throw new InvalidFleetMission(\sprintf('Réservoirs trop petits : ils contiennent au plus %d de deutérium.', $fleet->tankCapacity()));
            }
            // Partir réservoirs vides, c'est tomber en panne aussitôt : refusé ; au-delà, la panne en route reste possible
            if ($fleet->getFuel() + $fuel <= 0.0) {
                throw new InvalidFleetMission('Réservoirs vides : chargez du carburant pour partir.');
            }

            return $this->entityManager->wrapInTransaction(function () use ($fleet, $steps, $speedPercent, $cargo, $fuel, $planet, $loading): FleetMovement {
                $now = $this->clock->now();
                if ($loading) {
                    $this->load($fleet, $planet, $cargo, $fuel);
                }

                $fleet->replaceOrders();
                $this->entityManager->flush();
                foreach ($steps as $rank => $step) {
                    $fleet->addOrder(new FleetOrder($fleet, $rank + 1, SpaceLocation::of($step->destination), $step->action, mb_substr($step->label, 0, 60), $step->targetFleetId));
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

    /** Ravitaillement : une flotte de l'empire, autre que celle-ci, immobilisée en panne (§4.6.3) */
    private function checkRefuelTarget(Fleet $fleet, MissionStep $step, int $rank): void
    {
        $target = null === $step->targetFleetId ? null : $this->entityManager->find(Fleet::class, $step->targetFleetId);
        if (!$target instanceof Fleet || $target === $fleet || $target->getEmpire() !== $fleet->getEmpire()) {
            throw new InvalidFleetMission(\sprintf('Ordre %d : choisissez une flotte de l’empire à ravitailler.', $rank));
        }
        if (!$target->isStranded()) {
            throw new InvalidFleetMission(\sprintf('Ordre %d : la flotte « %s » n’est pas en panne de carburant.', $rank, $target->getName()));
        }
    }

    private function load(Fleet $fleet, Planet $planet, Resources $cargo, float $fuel): void
    {
        $snapshot = $this->resources->settle($planet);
        $taken = $cargo->plus(new Resources(0, 0, $fuel));
        if (!$snapshot->amounts->covers($taken)) {
            throw new InvalidFleetMission('La planète n’a pas les ressources à charger (cargaison et carburant).');
        }
        $planet->storeResources($snapshot->amounts->minus($taken), $snapshot->at);
        $fleet->load($cargo);
        $fleet->refuel($fuel);
    }
}
