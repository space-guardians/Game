<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AllianceInvitation;
use App\Entity\AllianceMember;
use App\Entity\AlliancePact;
use App\Entity\AlliancePlanet;
use App\Entity\Empire;
use App\Entity\TeleportLink;
use App\Enum\Alliance\AllianceBuildingType;
use App\Enum\Alliance\AllianceRole;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Repository\AllianceMemberRepository;
use App\Repository\AlliancePactRepository;
use App\Repository\AlliancePlanetRepository;
use App\Repository\AllianceRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\ShipyardOrderRepository;
use App\Repository\TeleportLinkRepository;
use App\Service\Account\GameContext;
use App\Service\Alliance\AlliancePlanets;
use App\Service\Alliance\Alliances;
use App\Service\Alliance\AllianceScanner;
use App\Service\Alliance\Pacts;
use App\Service\Alliance\TeleportGates;
use App\Service\Economy\PlanetResources;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Alliance » (§4.10) : sans alliance, la fonder ou accepter une invitation ; membre, consulter l'alliance et
 * ses membres, inviter (fondateur, diplomates), gérer les rôles, exclure et régler le partage des rapports de combat
 * (fondateur), partir ou dissoudre ; planète d'alliance (construction par le fondateur, bâtiments uniques et défenses
 * par le fondateur et les diplomates). Pactes de non-agression (§4.10.1), avec ou sans alliance. Toutes
 * les actions passent en POST avec le jeton CSRF « alliance ».
 */
final class AllianceController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly AllianceRepository $alliances,
        private readonly AllianceMemberRepository $members,
        private readonly Alliances $service,
        private readonly Pacts $pacts,
        private readonly AlliancePactRepository $pactRepository,
        private readonly AlliancePlanets $alliancePlanets,
        private readonly AlliancePlanetRepository $alliancePlanetRepository,
        private readonly ShipTypeRepository $shipTypes,
        private readonly ShipyardOrderRepository $shipyardOrders,
        private readonly PlanetResources $resources,
        private readonly AllianceScanner $scanner,
        private readonly TeleportGates $teleportGates,
        private readonly TeleportLinkRepository $teleportLinks,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/alliance', name: 'app_alliance', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->empire();
        $member = $this->members->findOneByEmpire($empire);
        $alliancePlanets = null === $member ? [] : $this->alliancePlanetRepository->findOf($member->getAlliance());

        return $this->render('alliance/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'member' => $member,
            'alliance' => $member?->getAlliance(),
            'invitations' => null === $member ? $this->alliances->findInvitationsFor($empire) : [],
            'pending' => null !== $member && $member->getRole()->canRecruit() ? $this->alliances->findInvitationsOf($member->getAlliance()) : [],
            'roles' => [AllianceRole::Diplomat, AllianceRole::Member],
            'pacts' => $this->pactRepository->findOpenFor($empire, $this->clock->now()),
            'max_pact_days' => AlliancePact::MAX_DAYS,
            'now' => $this->clock->now(),
            'alliance_planets' => array_map(fn(AlliancePlanet $alliancePlanet): array => [
                'planet' => $alliancePlanet,
                'stock' => $this->resources->snapshot($alliancePlanet->getPlanet())->amounts,
                'orders' => $this->shipyardOrders->findForPlanet($alliancePlanet->getPlanet()),
                'scan_range' => $this->scanner->range($alliancePlanet),
                'scan_contacts' => $this->scanner->contacts($alliancePlanet),
                'link' => $this->teleportLinks->findFor($alliancePlanet),
            ], $alliancePlanets),
            'can_build_planet' => \count($alliancePlanets) < AlliancePlanets::MAX_PER_ALLIANCE,
            'max_alliance_planets' => AlliancePlanets::MAX_PER_ALLIANCE,
            'alliance_building_types' => AllianceBuildingType::cases(),
            'gate_type' => AllianceBuildingType::TeleportGate,
            'defense_types' => $this->shipTypes->findDefensesOrdered(),
            'alliance_planet_cost' => AlliancePlanets::cost(),
            'alliance_planet_hours' => AlliancePlanets::BUILD_HOURS,
        ]);
    }

    #[Route('/alliance/partage', name: 'app_alliance_share', methods: ['POST'])]
    public function share(Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($request): string {
            $share = $request->request->getBoolean('share');
            $this->service->shareCombatReports($empire, $share);

            return $share ? 'Les rapports de combat des membres sont désormais partagés avec l’alliance.' : 'Les rapports de combat ne sont plus partagés.';
        });
    }

    #[Route('/alliance/pactes', name: 'app_alliance_pact_propose', methods: ['POST'])]
    public function proposePact(Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($request): string {
            $pact = $this->pacts->propose($empire, $request->request->getString('name'), $request->request->getInt('days'));

            return \sprintf('Pacte de %d jours proposé à %s.', $pact->getDays(), $pact->getPartner()->getName());
        });
    }

    #[Route('/alliance/pactes/{id}/{decision}', name: 'app_alliance_pact_answer', methods: ['POST'], requirements: ['id' => '\d+', 'decision' => 'accepter|refuser'])]
    public function answerPact(AlliancePact $pact, string $decision, Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($pact, $decision): string {
            $other = $pact->counterpart($empire)->getName();
            if ('accepter' === $decision) {
                $this->pacts->accept($pact, $empire);

                return \sprintf('Pacte conclu avec %s jusqu’au %s.', $other, $pact->getEndsAt()?->format('d/m/Y'));
            }
            $this->pacts->dismiss($pact, $empire);

            return $pact->getProposer() === $empire ? \sprintf('Proposition à %s retirée.', $other) : \sprintf('Proposition de %s refusée.', $other);
        });
    }

    #[Route('/alliance/planete', name: 'app_alliance_planet_build', methods: ['POST'])]
    public function buildPlanet(Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($request): string {
            $alliancePlanet = $this->alliancePlanets->build(
                $empire,
                $request->request->getInt('galaxy'),
                $request->request->getInt('system'),
                $request->request->getInt('orbit'),
                $request->request->getString('name'),
            );

            return \sprintf('Construction de %s lancée en %s : achevée le %s.', $alliancePlanet->getName(), $alliancePlanet->getPlanet(), $alliancePlanet->getCompletesAt()->format('d/m H:i'));
        });
    }

    #[Route('/alliance/planete/{id}/batiments/{type}', name: 'app_alliance_planet_raise', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function raiseBuilding(AlliancePlanet $alliancePlanet, AllianceBuildingType $type, Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($alliancePlanet, $type): string {
            $level = $this->alliancePlanets->raise($empire, $alliancePlanet, $type);

            return \sprintf('%s porté au niveau %d.', $type->label(), $level);
        });
    }

    #[Route('/alliance/planete/{id}/defenses', name: 'app_alliance_planet_defenses', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function orderDefenses(AlliancePlanet $alliancePlanet, Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($alliancePlanet, $request): string {
            $type = $this->shipTypes->findOneByCode($request->request->getString('type')) ?? throw new InvalidAllianceAction('Défense inconnue.');
            $order = $this->alliancePlanets->orderDefenses($empire, $alliancePlanet, $type, $request->request->getInt('quantity'));

            return \sprintf('%d × %s commandé(s) : livraison le %s.', $order->getQuantity(), $type->getName(), $order->getEndsAt()->format('d/m H:i'));
        });
    }

    #[Route('/alliance/planete/{id}/porte', name: 'app_alliance_gate_link', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function linkGate(AlliancePlanet $alliancePlanet, Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($alliancePlanet, $request): string {
            $other = $this->alliancePlanetRepository->find($request->request->getInt('gate')) ?? throw new InvalidAllianceAction('Porte inconnue.');
            $link = $this->teleportGates->link($empire, $alliancePlanet, $other);

            return \sprintf('Portes reliées : %s ↔ %s.', $link->getGateA()->getName(), $link->getGateB()->getName());
        });
    }

    #[Route('/alliance/portes/{id}/supprimer', name: 'app_alliance_gate_unlink', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unlinkGate(TeleportLink $link, Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($link): string {
            $label = \sprintf('%s ↔ %s', $link->getGateA()->getName(), $link->getGateB()->getName());
            $this->teleportGates->unlink($empire, $link);

            return \sprintf('Liaison %s rompue.', $label);
        });
    }

    #[Route('/alliance/fonder', name: 'app_alliance_found', methods: ['POST'])]
    public function found(Request $request): Response
    {
        return $this->act($request, fn(Empire $empire): string => \sprintf('Alliance %s fondée.', $this->service->found(
            $empire,
            $request->request->getString('name'),
            $request->request->getString('tag'),
            $request->request->getString('description'),
        )));
    }

    #[Route('/alliance/inviter', name: 'app_alliance_invite', methods: ['POST'])]
    public function invite(Request $request): Response
    {
        return $this->act($request, fn(Empire $empire): string => \sprintf('Invitation envoyée à %s.', $this->service->invite($empire, $request->request->getString('name'))->getEmpire()->getName()));
    }

    #[Route('/alliance/description', name: 'app_alliance_describe', methods: ['POST'])]
    public function describe(Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($request): string {
            $this->service->describe($empire, $request->request->getString('description'));

            return 'Description mise à jour.';
        });
    }

    #[Route('/alliance/invitations/{id}/{decision}', name: 'app_alliance_answer', methods: ['POST'], requirements: ['id' => '\d+', 'decision' => 'accepter|refuser'])]
    public function answer(AllianceInvitation $invitation, string $decision, Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($invitation, $decision): string {
            $alliance = (string) $invitation->getAlliance();
            if ('accepter' === $decision) {
                $this->service->accept($invitation, $empire);

                return \sprintf('Bienvenue dans l’alliance %s.', $alliance);
            }
            $this->service->decline($invitation, $empire);

            return \sprintf('Invitation de %s refusée.', $alliance);
        });
    }

    #[Route('/alliance/membres/{id}/{operation}', name: 'app_alliance_manage', methods: ['POST'], requirements: ['id' => '\d+', 'operation' => 'role|exclure|fondateur'])]
    public function manage(AllianceMember $member, string $operation, Request $request): Response
    {
        return $this->act($request, function (Empire $empire) use ($member, $operation, $request): string {
            $name = $member->getEmpire()->getName();
            switch ($operation) {
                case 'exclure':
                    $this->service->expel($empire, $member);

                    return \sprintf('%s a été exclu de l’alliance.', $name);
                case 'fondateur':
                    $this->service->transferLeadership($empire, $member);

                    return \sprintf('%s est désormais fondateur.', $name);
                default:
                    $role = AllianceRole::tryFrom($request->request->getString('role')) ?? throw new InvalidAllianceAction('Rôle inconnu.');
                    $this->service->setRole($empire, $member, $role);

                    return \sprintf('%s est désormais %s.', $name, mb_strtolower($role->label()));
            }
        });
    }

    #[Route('/alliance/quitter', name: 'app_alliance_leave', methods: ['POST'])]
    public function leave(Request $request): Response
    {
        return $this->act($request, function (Empire $empire): string {
            $this->service->leave($empire);

            return 'Vous avez quitté l’alliance.';
        });
    }

    #[Route('/alliance/dissoudre', name: 'app_alliance_dissolve', methods: ['POST'])]
    public function dissolve(Request $request): Response
    {
        return $this->act($request, function (Empire $empire): string {
            $this->service->dissolve($empire);

            return 'Alliance dissoute.';
        });
    }

    /** @param callable(Empire): string $action */
    private function act(Request $request, callable $action): Response
    {
        $empire = $this->empire();
        if (!$this->isCsrfTokenValid('alliance', $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');
        } else {
            try {
                $this->addFlash('success', $action($empire));
            } catch (InvalidAllianceAction $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_alliance');
    }

    private function empire(): Empire
    {
        return $this->context->empire() ?? throw $this->createAccessDeniedException();
    }
}
