<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Service\Notification\Notifications;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Notifications créées pendant un traitement (§4.12.3) : poussées en direct une fois la résolution d'un événement
 * validée, ou avec la réponse d'une requête (nouveau message…), oubliées en cas d'échec.
 */
final readonly class NotificationPublisher
{
    public function __construct(
        private Notifications $notifications,
    ) {}

    #[AsEventListener]
    public function onResolved(ScheduledEventResolved $resolved): void
    {
        if (ScheduledEventStatus::Done === $resolved->event->getStatus()) {
            $this->notifications->publish();
        } else {
            $this->notifications->discard();
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->notifications->hasPending()) {
            $this->notifications->publish();
        }
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $this->notifications->discard();
    }
}
