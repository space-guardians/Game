<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\PlayerQuest;
use App\Enum\Fleet\FleetAction;
use App\Exception\Market\InvalidMarketAction;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Repository\FleetRepository;
use App\Repository\PlayerQuestRepository;
use App\Service\Account\GameContext;
use App\Service\Combat\Battles;
use App\Service\Fleet\DestinationResolver;
use App\Service\Market\Escorts;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Escortes » (§4.15) : contrats d'escorte ouverts (départ seulement : la destination reste secrète), publication
 * d'un contrat pour l'une de ses flottes, contrats postés ou escortés (destination et arrivée connues des parties).
 */
final class EscortController extends AbstractController
{
    public const string TOKEN = 'escort';

    public function __construct(
        private readonly GameContext $context,
        private readonly Escorts $escorts,
        private readonly PlayerQuestRepository $quests,
        private readonly FleetRepository $fleets,
        private readonly DestinationResolver $destinations,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/escortes', name: 'app_escorts', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->empire();
        $fleets = $this->fleets->findOwnedBy($empire);
        $open = $this->quests->findOpenForOthers($empire);
        // Pour chaque contrat ouvert, les flottes de l'empire stationnées à son point de départ
        $candidates = [];
        foreach ($open as $quest) {
            $candidates[(int) $quest->getId()] = array_values(array_filter($fleets, static fn(Fleet $fleet): bool => $fleet->isStationed() && Battles::samePlace($fleet->getLocation(), $quest->getDeparture())));
        }

        return $this->render('escort/index.html.twig', [
            'empire' => $empire,
            'open' => $open,
            'candidates' => $candidates,
            'involved' => $this->quests->findInvolving($empire),
            'convoys' => array_values(array_filter($fleets, static fn(Fleet $fleet): bool => $fleet->isAtHome())),
            'earliest' => $this->clock->now()->modify(\sprintf('+%d minutes', Escorts::MIN_NOTICE_MINUTES + 5)),
            'min_notice' => Escorts::MIN_NOTICE_MINUTES,
            'max_notice' => Escorts::MAX_NOTICE_DAYS,
            'max_escorts' => PlayerQuest::MAX_ESCORTS,
            'token' => self::TOKEN,
        ]);
    }

    #[Route('/escortes/contrats', name: 'app_escorts_post', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($empire, $request): string {
            $convoy = $this->ownFleet($empire, $request->request->getInt('fleet'));
            $orbit = $request->request->getString('position');
            $target = $this->destinations->resolve($request->request->getInt('galaxy'), $request->request->getInt('system'), '' === $orbit ? null : (int) $orbit);
            if (null === $target) {
                throw new InvalidMarketAction('Destination inconnue : vérifiez les coordonnées.');
            }
            $action = FleetAction::tryFrom($request->request->getString('action')) ?? FleetAction::Station;
            try {
                $departsAt = new \DateTimeImmutable($request->request->getString('departs_at'), $this->clock->now()->getTimezone());
            } catch (\Exception) {
                throw new InvalidMarketAction('Indiquez la date et l’heure du départ.');
            }
            $quest = $this->escorts->post($empire, $convoy, new MissionStep($target['position'], $action, $target['label']), $departsAt, $this->resources($request, 'reward'), $this->resources($request, 'cargo'));

            return \sprintf('Contrat publié : départ de %s le %s. La récompense est bloquée jusqu’à l’arrivée.', $quest->getDepartureLabel(), $quest->getDepartsAt()->format('d/m à H:i'));
        });
    }

    #[Route('/escortes/contrats/{id}/escorter', name: 'app_escorts_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(PlayerQuest $quest, Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($quest, $empire, $request): string {
            $this->escorts->accept($quest, $empire, $this->ownFleet($empire, $request->request->getInt('fleet')));

            return \sprintf('Escorte engagée : destination %s. Restez stationné au départ jusqu’au %s.', $quest->getDestinationLabel(), $quest->getDepartsAt()->format('d/m à H:i'));
        });
    }

    #[Route('/escortes/contrats/{id}/annuler', name: 'app_escorts_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(PlayerQuest $quest, Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($quest, $empire): string {
            $this->escorts->cancel($quest, $empire);

            return 'Contrat annulé : la récompense vous revient.';
        });
    }

    #[Route('/escortes/contrats/{id}/quitter', name: 'app_escorts_leave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function leave(PlayerQuest $quest, Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($quest, $empire): string {
            $this->escorts->leave($quest, $empire);

            return 'Vous n’escortez plus ce convoi.';
        });
    }

    /** @param callable(): string $action */
    private function act(Request $request, callable $action): Response
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');
        } else {
            try {
                $this->addFlash('success', $action());
            } catch (InvalidMarketAction $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_escorts');
    }

    /** @throws InvalidMarketAction */
    private function ownFleet(Empire $empire, int $id): Fleet
    {
        $fleet = $this->fleets->find($id);
        if (!$fleet instanceof Fleet || $fleet->getEmpire() !== $empire) {
            throw new InvalidMarketAction('Choisissez l’une de vos flottes.');
        }

        return $fleet;
    }

    private function resources(Request $request, string $key): Resources
    {
        $values = $request->request->all($key);
        $amount = static fn(string $resource): float => max(0.0, floor((float) ($values[$resource] ?? 0)));

        return new Resources($amount('metal'), $amount('crystal'), $amount('deuterium'));
    }

    private function empire(): Empire
    {
        return $this->context->empire() ?? throw $this->createAccessDeniedException();
    }
}
