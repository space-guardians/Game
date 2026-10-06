<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ScheduledEvent;

/**
 * Un événement planifié vient d'être résolu (avec succès ou en échec), transaction validée : les écouteurs en
 * informent le joueur (notifications Mercure, §5.2).
 */
final readonly class ScheduledEventResolved
{
    public function __construct(
        public ScheduledEvent $event,
    ) {}
}
