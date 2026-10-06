<?php

declare(strict_types=1);

namespace App\Model\Admin;

/**
 * Indicateurs clés du tableau de bord d'administration (§5.6.1), calculés à l'affichage.
 */
final readonly class DashboardIndicators
{
    /**
     * @param array<string, int> $pendingEvents événements de jeu en attente, par libellé de type
     */
    public function __construct(
        public int $registeredPlayers,
        public int $activeToday,
        public int $activeThisWeek,
        public int $newToday,
        public int $newThisWeek,
        public array $pendingEvents,
        public int $failedMessages,
        public int $lateEvents,
        public int $failedEvents,
    ) {}

    public function pendingEventCount(): int
    {
        return array_sum($this->pendingEvents);
    }

    public function hasAlerts(): bool
    {
        return $this->failedMessages + $this->lateEvents + $this->failedEvents > 0;
    }
}
