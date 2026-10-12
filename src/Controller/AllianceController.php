<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AllianceInvitation;
use App\Entity\AllianceMember;
use App\Entity\AlliancePact;
use App\Entity\Empire;
use App\Enum\Alliance\AllianceRole;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Repository\AllianceMemberRepository;
use App\Repository\AlliancePactRepository;
use App\Repository\AllianceRepository;
use App\Service\Account\GameContext;
use App\Service\Alliance\Alliances;
use App\Service\Alliance\Pacts;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Alliance » (§4.10) : sans alliance, la fonder ou accepter une invitation ; membre, consulter l'alliance et
 * ses membres, inviter (fondateur, diplomates), gérer les rôles, exclure et régler le partage des rapports de combat
 * (fondateur), partir ou dissoudre. Pactes de non-agression (§4.10.1), avec ou sans alliance. Toutes
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
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/alliance', name: 'app_alliance', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->empire();
        $member = $this->members->findOneByEmpire($empire);

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
