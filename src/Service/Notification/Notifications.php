<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Empire;
use App\Entity\Notification;
use App\Enum\Notification\NotificationType;
use App\Model\Scheduling\GameTopics;
use App\Repository\NotificationRepository;
use App\Service\Combat\IncomingAttacks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;

/**
 * Notifications (§4.12.3) : le code de jeu les crée pendant un traitement (`notify()`, enregistrées dans la même
 * transaction, donc annulées avec elle) ; elles sont poussées en direct sur le topic de l'empire une fois le
 * traitement validé (`NotificationPublisher` : badge des non lues, liste de l'écran Notifications), jamais s'il a
 * échoué.
 */
final class Notifications implements ResetInterface
{
    /** @var list<Notification> */
    private array $pending = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notifications,
        private readonly UrlGeneratorInterface $urls,
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly IncomingAttacks $incomingAttacks,
    ) {}

    /**
     * Crée une notification ; l'appelant enregistre (flush), elle part à la validation du traitement.
     *
     * @param array<string, mixed> $parameters paramètres de la route de l'écran concerné
     */
    public function notify(Empire $empire, NotificationType $type, string $text, ?string $route = null, array $parameters = []): Notification
    {
        $notification = new Notification($empire, $type, $text, null === $route ? null : $this->urls->generate($route, $parameters), $this->clock->now());
        $this->entityManager->persist($notification);
        $this->pending[] = $notification;

        return $notification;
    }

    public function hasPending(): bool
    {
        return [] !== $this->pending;
    }

    /** Pousse en direct les notifications du traitement validé */
    public function publish(): void
    {
        $notifications = $this->pending;
        $this->pending = [];
        if (!$this->entityManager->isOpen()) {
            return;
        }
        foreach ($notifications as $notification) {
            if (null === $notification->getId()) {
                // Jamais enregistrée (traitement interrompu avant l'écriture)
                continue;
            }
            $empire = $notification->getEmpire();
            try {
                $this->hub->publish(new Update(
                    GameTopics::empire($empire),
                    $this->twig->render('streams/notification.stream.html.twig', [
                        'notification' => $notification,
                        'unread' => $this->notifications->countUnread($empire),
                    ] + (NotificationType::IncomingAttack === $notification->getType() ? ['attacks' => $this->incomingAttacks->of($empire), 'now' => $this->clock->now()] : [])),
                    private: true,
                ));
            } catch (\Throwable $exception) {
                $this->logger->warning('Notification non publiée (empire #{id}).', ['id' => $empire->getId(), 'exception' => $exception]);
            }
        }
    }

    public function discard(): void
    {
        $this->pending = [];
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    public function unreadCount(Empire $empire): int
    {
        return $this->notifications->countUnread($empire);
    }

    public function markAllRead(Empire $empire): void
    {
        $this->notifications->markAllRead($empire, $this->clock->now());
    }
}
