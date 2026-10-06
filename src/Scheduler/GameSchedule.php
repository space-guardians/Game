<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\ResolveDueEvents;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tâches récurrentes du jeu, consommées par le worker (transport « scheduler_default »).
 */
#[AsSchedule]
final readonly class GameSchedule implements ScheduleProviderInterface
{
    /** Intervalle du filet de sécurité des événements planifiés ; le réveil différé reste la voie normale */
    public const string DUE_EVENTS_FREQUENCY = '30 seconds';

    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return new Schedule()
            // Reprend après un arrêt du worker sans rejouer chaque échéance manquée
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every(self::DUE_EVENTS_FREQUENCY, new ResolveDueEvents()));
    }
}
