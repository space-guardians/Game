<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Note la dernière activité des joueurs connectés, pour les joueurs actifs du tableau de bord (§5.6.1). Une
 * écriture au plus toutes les INTERVAL secondes par joueur : la précision suffit à compter les actifs du jour.
 */
#[AsEventListener]
final readonly class PlayerActivityRecorder
{
    public const int INTERVAL = 300;

    public function __construct(
        private Security $security,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        $user = $event->isMainRequest() ? $this->security->getUser() : null;
        if (!$user instanceof User) {
            return;
        }

        $now = $this->clock->now();
        $lastActiveAt = $user->getLastActiveAt();
        if (null === $lastActiveAt || $now->getTimestamp() - $lastActiveAt->getTimestamp() >= self::INTERVAL) {
            $this->users->recordActivity($user, $now);
        }
    }
}
