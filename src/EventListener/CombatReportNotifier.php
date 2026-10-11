<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Service\Combat\CombatReports;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Rapports de combat rédigés pendant la résolution d'un événement (bataille programmée, interception à une arrivée) :
 * notifiés aux empires engagés une fois la résolution validée, oubliés si elle a échoué (§4.7).
 */
#[AsEventListener]
final readonly class CombatReportNotifier
{
    public function __construct(
        private CombatReports $reports,
    ) {}

    public function __invoke(ScheduledEventResolved $resolved): void
    {
        if (ScheduledEventStatus::Done === $resolved->event->getStatus()) {
            $this->reports->notify();
        } else {
            $this->reports->discard();
        }
    }
}
