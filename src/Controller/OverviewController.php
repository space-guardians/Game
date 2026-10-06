<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planet;
use App\Service\Account\GameContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vue d'ensemble de la planète active (§5.4), écran d'arrivée du joueur connecté, et sélecteur de planète active.
 */
final class OverviewController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        // Compte créé avant les empires (#16) : rien à afficher
        if (null === $empire) {
            return $this->render('overview/no_empire.html.twig');
        }

        return $this->render('overview/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
        ]);
    }

    #[Route('/planete-active', name: 'app_active_planet', methods: ['POST'])]
    public function switchPlanet(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('active-planet', $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_home');
        }

        $empire = $this->context->empire();
        $planetId = $request->request->getInt('planet');
        // Planète inconnue ou d'un autre empire : même réponse, rien ne fuite
        $planet = array_find($this->context->planets(), static fn(Planet $planet): bool => $planet->getId() === $planetId);
        if (null === $empire || null === $planet) {
            throw new NotFoundHttpException('Planète introuvable.');
        }

        $empire->switchTo($planet);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_home');
    }
}
