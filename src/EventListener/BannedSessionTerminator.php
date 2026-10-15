<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Security\BannedUserChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Un joueur banni pendant sa session (§4.13) est déconnecté à sa requête suivante et renvoyé à la connexion, avec le
 * motif.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final readonly class BannedSessionTerminator
{
    public function __construct(
        private Security $security,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urls,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        $user = $event->isMainRequest() ? $this->security->getUser() : null;
        if (!$user instanceof User || !$user->isBannedAt($this->clock->now())) {
            return;
        }
        $message = BannedUserChecker::message($user);
        $this->security->logout(false);
        $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $message);
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }
}
