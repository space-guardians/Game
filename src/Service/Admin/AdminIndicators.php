<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Model\Admin\DashboardIndicators;
use App\Repository\ScheduledEventRepository;
use App\Repository\UserRepository;
use App\Service\Economy\BuildingCompletedHandler;
use App\Service\Fleet\FleetArrivalHandler;
use App\Service\Fleet\ShipyardOrderCompletedHandler;
use App\Service\Research\ResearchCompletedHandler;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * Indicateurs du tableau de bord d'administration (§5.6.1). Les flottes en vol se comptent par leurs arrivées
 * planifiées ; les batailles programmées s'y ajouteront avec leur phase, comme nouveau type d'événement de jeu.
 */
final readonly class AdminIndicators
{
    /** Libellés des types d'événements planifiés (ScheduledEventHandler::type()) */
    public const array EVENT_LABELS = [
        BuildingCompletedHandler::TYPE => 'Constructions en cours',
        ResearchCompletedHandler::TYPE => 'Recherches en cours',
        ShipyardOrderCompletedHandler::TYPE => 'Commandes du chantier spatial',
        FleetArrivalHandler::TYPE => 'Flottes en vol',
    ];

    /**
     * Retard (secondes) au-delà duquel un événement échu non résolu est une anomalie : la vérification
     * périodique (GameSchedule) passe toutes les 30 secondes.
     */
    public const int LATE_AFTER = 120;

    public function __construct(
        private UserRepository $users,
        private ScheduledEventRepository $events,
        #[Autowire(service: 'messenger.transport.failed')]
        private MessageCountAwareInterface $failedTransport,
        private ClockInterface $clock,
    ) {}

    public function current(): DashboardIndicators
    {
        $now = $this->clock->now();
        $dayAgo = $now->modify('-1 day');
        $weekAgo = $now->modify('-7 days');

        $pendingEvents = [];
        foreach ($this->events->countPendingByType() as $type => $count) {
            $pendingEvents[self::EVENT_LABELS[$type] ?? $type] = $count;
        }

        return new DashboardIndicators(
            registeredPlayers: $this->users->count(),
            activeToday: $this->users->countActiveSince($dayAgo),
            activeThisWeek: $this->users->countActiveSince($weekAgo),
            newToday: $this->users->countRegisteredSince($dayAgo),
            newThisWeek: $this->users->countRegisteredSince($weekAgo),
            pendingEvents: $pendingEvents,
            failedMessages: $this->failedTransport->getMessageCount(),
            lateEvents: $this->events->countLate($now->modify(\sprintf('-%d seconds', self::LATE_AFTER))),
            failedEvents: $this->events->countFailedSince($weekAgo),
        );
    }
}
