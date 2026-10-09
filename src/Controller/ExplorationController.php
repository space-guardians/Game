<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ExplorationEventInstance;
use App\Entity\QuestOutcome;
use App\Exception\Exploration\InvalidQuestChoice;
use App\Repository\ExplorationEventInstanceRepository;
use App\Service\Account\GameContext;
use App\Service\Exploration\Explorations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Exploration » (§4.6.4) : journal des quêtes et événements vécus par les flottes de l'empire, avec les
 * décisions qu'ils attendent. Une flotte attend sur place la décision qui la concerne.
 */
final class ExplorationController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly ExplorationEventInstanceRepository $events,
        private readonly Explorations $explorations,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/exploration', name: 'app_exploration', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('exploration/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'events' => $this->events->findForEmpire($empire),
        ]);
    }

    /** Décision du joueur sur une quête à choix */
    #[Route('/exploration/{id}/choisir', name: 'app_exploration_choose', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function choose(ExplorationEventInstance $event, Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire || $event->getEmpire() !== $empire) {
            throw $this->createNotFoundException('Événement introuvable.');
        }
        if (!$this->isCsrfTokenValid('exploration-' . $event->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_exploration');
        }
        $outcome = $this->entityManager->find(QuestOutcome::class, $request->request->getInt('outcome'));

        try {
            if (!$outcome instanceof QuestOutcome) {
                throw new InvalidQuestChoice('Choisissez une des issues proposées.');
            }
            $this->explorations->choose($event, $outcome, $empire);
            $this->addFlash('success', \sprintf('« %s » : %s.', $event->getTitle(), $outcome->getLabel()));
        } catch (InvalidQuestChoice $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_exploration', ['_fragment' => 'evenement-' . $event->getId()]);
    }
}
