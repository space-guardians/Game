<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Service\Messaging\GameReports;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rapports automatiques notés pendant un traitement (§4.12.2) : délivrés dans la messagerie une fois la résolution d'un
 * événement validée, ou avec la réponse d'une requête (décision d'exploration…), oubliés en cas d'échec.
 */
final readonly class GameReportNotifier
{
    public function __construct(
        private GameReports $reports,
    ) {}

    #[AsEventListener]
    public function onResolved(ScheduledEventResolved $resolved): void
    {
        if (ScheduledEventStatus::Done === $resolved->event->getStatus()) {
            $this->reports->send();
        } else {
            $this->reports->discard();
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->reports->hasPending()) {
            $this->reports->send();
        }
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $this->reports->discard();
    }
}
