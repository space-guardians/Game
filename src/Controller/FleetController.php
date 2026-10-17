<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Enum\FairPlay\ActivityAction;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Exception\Fleet\InvalidFleetComposition;
use App\Exception\Fleet\InvalidFleetMission;
use App\Exception\Fleet\InvalidFormation;
use App\Model\Economy\Resources;
use App\Model\Fleet\FormationCell;
use App\Model\Fleet\MissionStep;
use App\Repository\BattleRepository;
use App\Repository\ExplorationEventInstanceRepository;
use App\Repository\FleetMovementRepository;
use App\Repository\FleetRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Account\GameContext;
use App\Service\Combat\Allegiances;
use App\Service\Combat\BattleBoard;
use App\Service\Combat\Battles;
use App\Service\FairPlay\ActionLimits;
use App\Service\FairPlay\ActivityLogger;
use App\Service\Fleet\DestinationResolver;
use App\Service\Fleet\FleetAssembly;
use App\Service\Fleet\FleetDispatch;
use App\Service\Fleet\Formations;
use App\Service\Fleet\TravelRules;
use App\Service\Universe\ColonyLimit;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Flotte » (§4.5, §4.6) : hangar de la planète active (vaisseaux en inventaire), constitution d'une flotte,
 * flottes de l'empire avec leur carnet d'ordres et leur déplacement en cours, formation, envoi en mission, dissolution.
 */
final class FleetController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly ShipTypeRepository $shipTypes,
        private readonly FleetRepository $fleets,
        private readonly FleetAssembly $assembly,
        private readonly FleetMovementRepository $movements,
        private readonly FleetDispatch $dispatch,
        private readonly DestinationResolver $destinations,
        private readonly ClockInterface $clock,
        private readonly Formations $formations,
        private readonly ExplorationEventInstanceRepository $explorationEvents,
        private readonly ColonyLimit $colonyLimit,
        private readonly BattleRepository $battles,
        private readonly BattleBoard $battleBoard,
        private readonly Allegiances $allegiances,
        private readonly ActivityLogger $activity,
        private readonly ActionLimits $limits,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** Ordres proposés dans le formulaire d'envoi ; le dernier suggère le retour au point de départ (§4.6) */
    private const int FORM_STEPS = 3;

    #[Route('/flotte', name: 'app_fleet', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        $planet = $empire->getActivePlanet();

        $hangar = [];
        foreach ($this->shipTypes->findShipsOrdered() as $type) {
            $count = $planet->shipCount($type);
            if ($count > 0) {
                $hangar[] = ['type' => $type, 'count' => $count];
            }
        }

        $fleets = $this->fleets->findOwnedBy($empire);
        $movements = [];
        foreach ($fleets as $fleet) {
            $movements[(int) $fleet->getId()] = $this->movements->findActiveFor($fleet);
        }

        return $this->render('fleet/index.html.twig', [
            'empire' => $empire,
            'planet' => $planet,
            'hangar' => $hangar,
            'fleets' => $fleets,
            'movements' => $movements,
            'awaiting' => $this->explorationEvents->findAwaitingByFleet($empire),
            'battles' => $this->battles->findScheduledByFleet($empire),
            'battle_board' => $this->reinforcements($empire, $fleets),
            // Flottes alliées en panne, que l'empire peut ravitailler (§4.6.3, §4.10.1)
            'allied_stranded' => $this->fleets->findStrandedOf($this->allegiances->alliesOf($empire)),
            'now' => $this->clock->now(),
            'name_max_length' => Fleet::NAME_MAX_LENGTH,
        ]);
    }

    #[Route('/flotte/constituer', name: 'app_fleet_assemble', methods: ['POST'])]
    public function assemble(Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        if (!$this->isCsrfTokenValid('assemble-fleet', $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_fleet');
        }

        $requested = $request->request->all('ships');
        $ships = [];
        foreach ($this->shipTypes->findAllOrdered() as $type) {
            $quantity = $requested[$type->getCode()] ?? '';
            if (\is_string($quantity) && '' !== $quantity) {
                $ships[] = ['type' => $type, 'quantity' => (int) $quantity];
            }
        }

        try {
            $fleet = $this->assembly->assemble($empire->getActivePlanet(), $request->request->getString('name'), $ships);
            $this->addFlash('success', \sprintf('Flotte « %s » constituée : %d vaisseau(x).', $fleet->getName(), $fleet->shipCount()));
        } catch (InvalidFleetComposition $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/flotte/{id}/dissoudre', name: 'app_fleet_disband', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function disband(Fleet $fleet, Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire || $fleet->getEmpire() !== $empire) {
            throw $this->createNotFoundException('Flotte introuvable.');
        }
        if (!$this->isCsrfTokenValid('disband-fleet-' . $fleet->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_fleet');
        }

        $name = $fleet->getName();
        try {
            $this->assembly->disband($fleet);
            $this->addFlash('success', \sprintf('Flotte « %s » dissoute : ses vaisseaux ont rejoint le hangar.', $name));
        } catch (InvalidFleetComposition $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_fleet');
    }

    /** Envoi en mission : carnet d'ordres « se déplacer puis agir », cargaison, vitesse */
    #[Route('/flotte/{id}/envoyer', name: 'app_fleet_dispatch', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function dispatch(Fleet $fleet, Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire || $fleet->getEmpire() !== $empire) {
            throw $this->createNotFoundException('Flotte introuvable.');
        }
        // Point de départ : planète, ou système entier pour une flotte stationnée au niveau d'un système (§4.6.2)
        $origin = $this->destinations->coordinatesOf($fleet->getLocation());

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('dispatch-fleet-' . $fleet->getId(), $request->request->getString('_token'))) {
                $this->addFlash('error', 'La page a expiré : recommencez.');

                return $this->redirectToRoute('app_fleet_dispatch', ['id' => $fleet->getId()]);
            }
            try {
                // Rythme des envois (§4.13)
                $this->limits->fleetDispatch($fleet->getEmpire());
                $movement = $this->dispatch->dispatch(
                    $fleet,
                    $this->steps($request->request->all('steps'), $fleet, $request->request->getInt('speed', 100)),
                    $request->request->getInt('speed', 100),
                    $this->cargo($request->request->all('cargo')),
                    max(0.0, (float) $request->request->get('fuel', 0)),
                    max(0.0, floor((float) $request->request->get('antimatter', 0))),
                );
                $this->activity->record($fleet->getEmpire()->getUser(), ActivityAction::FleetDispatch);
                $this->entityManager->flush();
                $this->addFlash('success', \sprintf('Flotte « %s » en route vers %s, arrivée à %s.', $fleet->getName(), $movement->getOrder()->getDestinationLabel(), $movement->getArrivesAt()->format('H:i:s')));
                if ($movement->isStranding()) {
                    $this->addFlash('error', \sprintf('Carburant insuffisant : la flotte tombera en panne en chemin, à %s.', $movement->getArrivesAt()->format('H:i:s')));
                }

                return $this->redirectToRoute('app_fleet');
            } catch (InvalidFleetMission $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        // Ravitaillement proposé depuis une flotte en panne (§4.6.3)
        $rescued = $this->fleets->find($request->query->getInt('ravitailler'));
        $rescued = $rescued instanceof Fleet && $this->allegiances->allied($rescued->getEmpire(), $empire) && $rescued->isStranded() && $rescued !== $fleet ? $rescued : null;
        // Renfort proposé depuis une bataille programmée (§4.7)
        $reinforced = $this->battles->find($request->query->getInt('bataille'));
        $reinforced = $reinforced instanceof Battle && $this->battleBoard->isVisibleTo($reinforced, $empire) ? $reinforced : null;
        $stock = $fleet->isAtHome() ? $this->context->activeResources()?->amounts : null;

        return $this->render('fleet/dispatch.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'fleet' => $fleet,
            'origin' => $origin,
            'rescued' => $rescued,
            'reinforced' => $reinforced,
            // Plein proposé : réservoirs complétés, dans la limite du deutérium de la planète
            'suggested_fuel' => null === $stock ? 0 : (int) floor(min($fleet->tankCapacity() - $fleet->getFuel(), $stock->deuterium)),
            // Emplacements de colonisation (§4.1), utiles quand la flotte emporte un colonisateur
            'colony_slots' => ($fleet->shipCounts()[ShipType::COLONY_SHIP] ?? 0) > 0 ? $this->colonyLimit->slots($empire) : null,
            'form_steps' => self::FORM_STEPS,
            'actions' => FleetAction::cases(),
            'speeds' => TravelRules::SPEED_PERCENTS,
            'stock' => $stock,
            'submitted' => $request->request->all(),
        ], new Response(status: $request->isMethod('POST') ? 422 : 200));
    }

    /** Formation de combat (§4.7) : répartition des vaisseaux sur la grille, réglable tant que la flotte est stationnée */
    #[Route('/flotte/{id}/formation', name: 'app_fleet_formation', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function formation(Fleet $fleet, Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire || $fleet->getEmpire() !== $empire) {
            throw $this->createNotFoundException('Flotte introuvable.');
        }
        $formation = $this->formations->of($fleet);
        $failed = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('formation-' . $fleet->getId(), $request->request->getString('_token'))) {
                $this->addFlash('error', 'La page a expiré : recommencez.');

                return $this->redirectToRoute('app_fleet_formation', ['id' => $fleet->getId()]);
            }
            try {
                $this->formations->arrange($fleet, $this->cells($request->request->all('cells')));
                $this->addFlash('success', \sprintf('Formation de « %s » enregistrée.', $fleet->getName()));

                return $this->redirectToRoute('app_fleet_formation', ['id' => $fleet->getId()]);
            } catch (InvalidFormation $exception) {
                $failed = true;
                foreach ($exception->violations as $violation) {
                    $this->addFlash('error', $violation);
                }
            }
        }

        return $this->render('fleet/formation.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'fleet' => $fleet,
            'formation' => $formation,
            'rows' => FormationRow::cases(),
            'columns' => FormationColumn::cases(),
            'submitted' => $failed ? $request->request->all('cells') : null,
        ], new Response(status: $failed ? 422 : 200));
    }

    /**
     * Cases saisies : cells[code][ligne-colonne] = nombre.
     *
     * @param array<mixed> $input
     *
     * @return list<FormationCell>
     */
    private function cells(array $input): array
    {
        $cells = [];
        foreach ($input as $ship => $byCell) {
            if (!\is_array($byCell)) {
                continue;
            }
            foreach (FormationRow::cases() as $row) {
                foreach (FormationColumn::cases() as $column) {
                    $quantity = (int) ($byCell[$row->value . '-' . $column->value] ?? 0);
                    if (0 !== $quantity) {
                        $cells[] = new FormationCell($row, $column, (string) $ship, $quantity);
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * Étapes saisies, dans l'ordre ; une ligne sans action est ignorée. Un ravitaillement vise une flotte (sa position
     * exacte), les autres actions des coordonnées.
     *
     * @param array<mixed> $rows
     *
     * @return list<MissionStep>
     *
     * @throws InvalidFleetMission
     */
    private function steps(array $rows, Fleet $fleet, int $speedPercent): array
    {
        $steps = [];
        foreach (array_values($rows) as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $action = FleetAction::tryFrom((string) ($row['action'] ?? ''));
            if (null === $action) {
                continue;
            }
            // Renfort vers une bataille programmée : sa position exacte, à condition d'arriver avant son heure
            if (FleetAction::JoinBattle === $action && isset($row['battle'])) {
                $battle = $this->battles->find((int) $row['battle']);
                $empire = $fleet->getEmpire();
                if (!$battle instanceof Battle || !$this->battleBoard->isVisibleTo($battle, $empire)) {
                    throw new InvalidFleetMission(\sprintf('Ordre %d : cette bataille n’est plus programmée.', $index + 1));
                }
                if (!$this->battleBoard->arrivesInTime($fleet, $battle, $speedPercent, $this->clock->now())) {
                    throw new InvalidFleetMission(\sprintf('Ordre %d : la flotte arriverait après la bataille (%s) ; augmentez la vitesse ou envoyez une flotte plus proche.', $index + 1, $battle->getResolvesAt()->format('H:i:s')));
                }
                $steps[] = new MissionStep($battle->getLocation()->toPosition(), $action, $battle->getLocationLabel());

                continue;
            }
            if (!$action->targetsCoordinates()) {
                $target = $this->fleets->find((int) ($row['fleet'] ?? 0));
                if (!$target instanceof Fleet || !$this->allegiances->allied($target->getEmpire(), $fleet->getEmpire())) {
                    throw new InvalidFleetMission(\sprintf('Ordre %d : choisissez une flotte de l’empire ou d’un allié à ravitailler.', $index + 1));
                }
                $steps[] = new MissionStep($target->getLocation()->toPosition(), $action, \sprintf('flotte « %s »', $target->getName()), (int) $target->getId());

                continue;
            }
            // Marché : la station du marchand du système saisi (§4.15)
            if ($action->targetsMerchant()) {
                $merchant = $this->destinations->merchant((int) ($row['galaxy'] ?? 0), (int) ($row['system'] ?? 0));
                if (null === $merchant) {
                    throw new InvalidFleetMission(\sprintf('Ordre %d : aucun marchand dans ce système.', $index + 1));
                }
                $steps[] = new MissionStep($merchant['position'], $action, $merchant['label']);

                continue;
            }
            $orbit = trim((string) ($row['position'] ?? ''));
            $destination = $this->destinations->resolve((int) ($row['galaxy'] ?? 0), (int) ($row['system'] ?? 0), '' === $orbit ? null : (int) $orbit);
            if (null === $destination) {
                throw new InvalidFleetMission(\sprintf('Ordre %d : ces coordonnées ne désignent ni une planète ni un système.', $index + 1));
            }
            $steps[] = new MissionStep($destination['position'], $action, $destination['label']);
        }

        return $steps;
    }

    /** @param array<mixed> $cargo */
    private function cargo(array $cargo): Resources
    {
        $amount = static fn(string $key): float => max(0.0, (float) ($cargo[$key] ?? 0));

        return new Resources($amount('metal'), $amount('crystal'), $amount('deuterium'));
    }

    /**
     * Batailles programmées que l'empire connaît, avec ses flottes stationnées qui peuvent y partir en renfort (ni déjà
     * engagées, ni déjà sur place).
     *
     * @param list<Fleet> $fleets
     *
     * @return list<array{battle: Battle, fleets: list<Fleet>}>
     */
    private function reinforcements(Empire $empire, array $fleets): array
    {
        $board = [];
        foreach ($this->battleBoard->visibleTo($empire) as $battle) {
            $available = array_values(array_filter($fleets, static fn(Fleet $fleet): bool => $fleet->isStationed()
                && null === $battle->participantFor($fleet)
                && !Battles::samePlace($fleet->getLocation(), $battle->getLocation())));
            $board[] = ['battle' => $battle, 'fleets' => $available];
        }

        return $board;
    }
}
