<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Service\Alliance\ScannerAlerts;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Trajets repérés par un scanner d'alliance (§4.10) : publiés une fois le départ validé — fin de la résolution d'un
 * événement (enchaînement d'ordres), ou fin de la requête (envoi d'une flotte) —, oubliés en cas d'échec.
 */
final readonly class ScannerAlertNotifier
{
    public function __construct(
        private ScannerAlerts $alerts,
    ) {}

    #[AsEventListener]
    public function onResolved(ScheduledEventResolved $resolved): void
    {
        if (ScheduledEventStatus::Done === $resolved->event->getStatus()) {
            $this->alerts->notify();
        } else {
            $this->alerts->discard();
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->alerts->hasPending()) {
            $this->alerts->notify();
        }
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $this->alerts->discard();
    }
}
