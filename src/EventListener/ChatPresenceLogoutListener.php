<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\EmpireRepository;
use App\Service\Chat\ChatPresence;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Déconnexion d'un joueur : il apparaît aussitôt hors ligne dans le chat (§4.12.1), sans attendre l'expiration de
 * son dernier battement.
 */
#[AsEventListener]
final readonly class ChatPresenceLogoutListener
{
    public function __construct(
        private EmpireRepository $empires,
        private ChatPresence $presence,
    ) {}

    public function __invoke(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        $empire = $user instanceof User ? $this->empires->findOneByUser($user) : null;
        if (null !== $empire) {
            $this->presence->leave($empire);
        }
    }
}
