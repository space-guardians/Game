<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AdminUser;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Règles de session du panneau d'administration (§5.6.2) :
 * - déconnexion après une période d'inactivité ;
 * - double authentification obligatoire : tant qu'elle n'est pas activée, seule la page d'activation est accessible.
 */
final readonly class AdminSessionSubscriber implements EventSubscriberInterface
{
    private const string LAST_ACTIVITY = 'admin_last_activity';
    /** Pages accessibles avant l'activation de la double authentification */
    private const array SETUP_ROUTES = ['admin_2fa_setup', 'admin_logout'];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        #[Autowire('%app.admin_idle_timeout%')]
        private int $idleTimeout,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (priorité 8), qui a chargé le compte connecté
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'admin' !== $this->security->getFirewallConfig($request)?->getName()) {
            return;
        }

        $admin = $this->security->getUser();
        if (!$admin instanceof AdminUser || $this->security->getToken() instanceof TwoFactorTokenInterface) {
            return;
        }

        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $lastActivity = $session->get(self::LAST_ACTIVITY);
        if (\is_int($lastActivity) && $now - $lastActivity > $this->idleTimeout) {
            $this->security->logout(false);
            $session = $request->getSession();
            \assert($session instanceof FlashBagAwareSessionInterface);
            $session->getFlashBag()->add('warning', 'Session expirée après une période d’inactivité : reconnectez-vous.');
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_login')));

            return;
        }
        $session->set(self::LAST_ACTIVITY, $now);

        if (!$admin->isTotpAuthenticationEnabled() && !\in_array($request->attributes->get('_route'), self::SETUP_ROUTES, true)) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_2fa_setup')));
        }
    }
}
