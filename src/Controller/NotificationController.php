<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NotificationRepository;
use App\Service\Account\GameContext;
use App\Service\Notification\Notifications;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Notifications » (§4.12.3) : historique paginé ; l'afficher marque tout comme lu (badge remis à zéro).
 */
final class NotificationController extends AbstractController
{
    public const int PAGE_SIZE = 50;

    public function __construct(
        private readonly GameContext $context,
        private readonly NotificationRepository $repository,
        private readonly Notifications $notifications,
    ) {}

    #[Route('/notifications', name: 'app_notifications', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        $page = max(1, $request->query->getInt('page', 1));
        $notifications = $this->repository->findPage($empire, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE + 1);
        // Vues, donc lues : badge à zéro ; les entités chargées gardent leur état d'avant, pour distinguer les nouvelles
        $this->notifications->markAllRead($empire);

        return $this->render('notification/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'notifications' => \array_slice($notifications, 0, self::PAGE_SIZE),
            'has_next' => \count($notifications) > self::PAGE_SIZE,
            'page' => $page,
        ]);
    }
}
