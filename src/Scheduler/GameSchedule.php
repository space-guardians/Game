<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\ConsolidateResources;
use App\Message\LaunchNpcRaids;
use App\Message\RefreshRankings;
use App\Message\ResolveDueEvents;
use App\Message\TakeEmpireSnapshots;
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

    /** Consolidation légère des stocks (§4.2) : seules les planètes non touchées depuis une heure sont écrites */
    public const string RESOURCES_CONSOLIDATION_FREQUENCY = '1 hour';

    /** Recalcul des scores et des classements (§4.11) */
    public const string RANKINGS_FREQUENCY = '15 minutes';

    /** Raids des civilisations non-joueuses mineures dont l'intervalle est écoulé (§4.14) */
    public const string NPC_RAIDS_FREQUENCY = '1 hour';

    /** Instantanés des empires pour le suivi de leur progression (§5.6.1, provisoire) */
    public const string SNAPSHOTS_FREQUENCY = '6 hours';

    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return new Schedule()
            // Reprend après un arrêt du worker sans rejouer chaque échéance manquée
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every(self::DUE_EVENTS_FREQUENCY, new ResolveDueEvents()))
            ->add(RecurringMessage::every(self::RESOURCES_CONSOLIDATION_FREQUENCY, new ConsolidateResources()))
            ->add(RecurringMessage::every(self::RANKINGS_FREQUENCY, new RefreshRankings()))
            ->add(RecurringMessage::every(self::NPC_RAIDS_FREQUENCY, new LaunchNpcRaids()))
            ->add(RecurringMessage::every(self::SNAPSHOTS_FREQUENCY, new TakeEmpireSnapshots()));
    }
}
