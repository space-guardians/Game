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
use App\Enum\Fleet\FleetStatus;
use App\Enum\Pve\NpcCategory;
use App\Exception\Fleet\InvalidFleetMission;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Repository\FleetRepository;
use App\Repository\MerchantRepository;
use App\Service\Alliance\TeleportGates;
use App\Service\Combat\Allegiances;
use App\Service\Economy\PlanetResources;
use App\Service\FairPlay\Protections;
use App\Service\Universe\ColonyLimit;
use App\Service\Universe\PlanetLocks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

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
        private PlanetLocks $planetLocks,
        private ClockInterface $clock,
        private ColonyLimit $colonyLimit,
        private Allegiances $allegiances,
        private TeleportGates $teleports,
        private MerchantRepository $merchants,
        private Protections $protections,
        private FleetRepository $fleets,
    ) {}

    /**
     * @param list<MissionStep> $steps
     * @param float             $fuel       deutérium à mettre dans les réservoirs, pris sur la planète de départ
     * @param float             $antimatter antimatière à charger (§4.14), prise sur la planète de départ
     *
     * @throws InvalidFleetMission
     */
    public function dispatch(Fleet $fleet, array $steps, int $speedPercent, Resources $cargo, float $fuel = 0.0, float $antimatter = 0.0): FleetMovement
    {
        // Mode vacances (§4.13) : les flottes restent à quai
        if ($fleet->getEmpire()->isOnVacation()) {
            throw new InvalidFleetMission('En mode vacances, vos flottes restent à quai.');
        }
        // Une civilisation « boss » est strictement défensive (§4.14) : ses flottes ne quittent jamais sa planète
        if (NpcCategory::Boss === $fleet->getEmpire()->getNpcProfile()?->getCategory()) {
            throw new InvalidFleetMission('Une civilisation « boss » est strictement défensive : ses flottes ne quittent pas sa planète.');
        }
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
            // Une flotte en vol ne peut pas être réaffectée à une bataille : seule une flotte stationnée part en renfort
            if (FleetAction::JoinBattle === $step->action && $rank > 0) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : « %s » ne peut être que le premier ordre : seule une flotte stationnée part en renfort.', $rank + 1, $step->action->label()));
            }
            if ($step->action->forbidsPlanet() && null !== $step->destination->planetId) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : « %s » ne peut pas viser une planète : visez un système (sans position).', $rank + 1, $step->action->label()));
            }
            if (FleetAction::Colonize === $step->action) {
                ++$colonizations;
            }
            if ($step->action->targetsMerchant() && null === $this->merchants->findAt(SpaceLocation::of($step->destination))) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : « %s » vise la station d’un marchand.', $rank + 1, $step->action->label()));
            }
            if (FleetAction::Refuel === $step->action) {
                $this->checkRefuelTarget($fleet, $step, $rank + 1);
            }
            // Porte de téléportation de son alliance, reliée et en service (vérifié à nouveau à l'arrivée)
            $gate = FleetAction::Teleport === $step->action && null !== $step->destination->planetId
                ? $this->entityManager->find(Planet::class, $step->destination->planetId)
                : null;
            $refusal = null === $gate ? null : $this->teleports->refusal($gate, $fleet->getEmpire());
            if (null !== $refusal) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : %s', $rank + 1, $refusal));
            }
            // Aucune action agressive entre alliés (§4.10.1) : pas d'attaque sur la planète d'un allié
            $target = FleetAction::JoinBattle === $step->action && null !== $step->destination->planetId
                ? $this->entityManager->find(Planet::class, $step->destination->planetId)
                : null;
            if (null !== $target && $this->allegiances->planetAllied($target, $fleet->getEmpire())) {
                $holder = $target->getOwner()?->getName() ?? $target->getAlliance()?->getName() ?? (string) $target;
                throw new InvalidFleetMission(\sprintf('Ordre %d : %s est un allié (même empire, alliance ou pacte) : pas d’attaque possible.', $rank + 1, $holder));
            }
            // Bouclier débutant et mode vacances (§4.13) : ni attaque ni espionnage sur une planète protégée
            $hostile = \in_array($step->action, [FleetAction::JoinBattle, FleetAction::Spy], true) && null !== $step->destination->planetId
                ? $this->entityManager->find(Planet::class, $step->destination->planetId)
                : null;
            $owner = $hostile?->getOwner();
            $protection = null === $owner || $owner === $fleet->getEmpire() ? null : $this->protections->refusal($owner);
            if (null !== $protection) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : %s', $rank + 1, $protection));
            }
        }
        // Espionnage : uniquement des sondes, et seule une sonde espionne (§4.6)
        foreach ($steps as $rank => $step) {
            if (FleetAction::Spy === $step->action && array_keys($fleet->shipCounts()) !== [ShipType::ESPIONAGE_PROBE]) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : « %s » demande une flotte composée uniquement de sondes d’espionnage.', $rank + 1, $step->action->label()));
            }
        }
        // Seuls les recycleurs récupèrent un champ de débris
        foreach ($steps as $rank => $step) {
            if (FleetAction::Recycle === $step->action && 0 === ($fleet->shipCounts()[ShipType::RECYCLER] ?? 0)) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : « %s » demande au moins un recycleur dans la flotte.', $rank + 1, $step->action->label()));
            }
        }
        // Chaque « Coloniser » consomme un colonisateur de la flotte
        if ($colonizations > $this->colonyShips($fleet)) {
            throw new InvalidFleetMission(\sprintf('« Coloniser » consomme un colonisateur : il en faut %d dans la flotte.', $colonizations));
        }
        // Emplacements libres d'après l'astrophysique (§4.1) ; vérifié à nouveau à l'arrivée
        $refusal = $colonizations > 0 ? $this->colonyLimit->slots($fleet->getEmpire())->refusal($colonizations) : null;
        if (null !== $refusal) {
            throw new InvalidFleetMission($refusal);
        }

        $planet = $fleet->getPlanet();
        $antimatter = max(0.0, $antimatter);
        $loading = $cargo->metal + $cargo->crystal + $cargo->deuterium > 0 || $fuel > 0 || $antimatter > 0;
        $lock = $this->planetLocks->acquireForFleet($fleet);

        try {
            // État relu en base sous le verrou : le worker a pu la faire partir ou la détruire depuis son chargement
            if (!$fleet->isStationed() || (null !== $fleet->getId() && FleetStatus::Stationed !== $this->fleets->statusOf($fleet))) {
                throw new InvalidFleetMission(\sprintf('La flotte « %s » n’est pas stationnée : elle exécute déjà une mission.', $fleet->getName()));
            }
            if ($loading && (null === $planet || !$fleet->isAtHome())) {
                throw new InvalidFleetMission('Une flotte ne charge des ressources que sur une planète de son empire.');
            }
            if ($loading && $cargo->metal + $cargo->crystal + $cargo->deuterium + $antimatter > $fleet->freeCargo()) {
                throw new InvalidFleetMission(\sprintf('Cargaison trop lourde : la flotte emporte au plus %d.', $fleet->cargo()));
            }
            // Stock relu sous le verrou de la planète ; vérifié avant la transaction, qu'un refus laisse intacte
            if ($antimatter > 0 && $antimatter > (float) $planet?->getAntimatter() + 1e-6) {
                throw new InvalidFleetMission('La planète n’a pas l’antimatière à charger.');
            }
            if ($fuel < 0 || $fleet->getFuel() + $fuel > $fleet->tankCapacity() + 1e-6) {
                throw new InvalidFleetMission(\sprintf('Réservoirs trop petits : ils contiennent au plus %d de deutérium.', $fleet->tankCapacity()));
            }
            // Partir réservoirs vides, c'est tomber en panne aussitôt : refusé ; au-delà, la panne en route reste possible
            if ($fleet->getFuel() + $fuel <= 0.0) {
                throw new InvalidFleetMission('Réservoirs vides : chargez du carburant pour partir.');
            }

            return $this->entityManager->wrapInTransaction(function () use ($fleet, $steps, $speedPercent, $cargo, $fuel, $antimatter, $planet, $loading): FleetMovement {
                $now = $this->clock->now();
                if ($loading) {
                    $this->load($fleet, $planet, $cargo, $fuel, $antimatter);
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
        // Une flotte de l'empire ou d'un allié (même alliance, pacte en vigueur)
        if (!$target instanceof Fleet || $target === $fleet || !$this->allegiances->allied($target->getEmpire(), $fleet->getEmpire())) {
            throw new InvalidFleetMission(\sprintf('Ordre %d : choisissez une flotte de l’empire ou d’un allié à ravitailler.', $rank));
        }
        if (!$target->isStranded()) {
            throw new InvalidFleetMission(\sprintf('Ordre %d : la flotte « %s » n’est pas en panne de carburant.', $rank, $target->getName()));
        }
    }

    private function load(Fleet $fleet, Planet $planet, Resources $cargo, float $fuel, float $antimatter): void
    {
        $snapshot = $this->resources->settle($planet);
        $taken = $cargo->plus(new Resources(0, 0, $fuel));
        if (!$snapshot->amounts->covers($taken)) {
            throw new InvalidFleetMission('La planète n’a pas les ressources à charger (cargaison et carburant).');
        }
        $planet->spendAntimatter($antimatter);
        $planet->storeResources($snapshot->amounts->minus($taken), $snapshot->at);
        $fleet->load($cargo);
        $fleet->loadAntimatter($antimatter);
        $fleet->refuel($fuel);
    }
}
