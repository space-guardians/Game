<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Service\Espionage\EspionageAlerts;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Sondes repérées pendant la résolution d'une arrivée : la cible est prévenue une fois la résolution validée, l'alerte
 * est oubliée si elle a échoué (§4.9).
 */
#[AsEventListener]
final readonly class EspionageAlertNotifier
{
    public function __construct(
        private EspionageAlerts $alerts,
    ) {}

    public function __invoke(ScheduledEventResolved $resolved): void
    {
        if (ScheduledEventStatus::Done === $resolved->event->getStatus()) {
            $this->alerts->notify();
        } else {
            $this->alerts->discard();
        }
    }
}
