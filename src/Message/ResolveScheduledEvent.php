<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Réveil différé d'un événement planifié, envoyé à sa création pour arriver à son échéance.
 */
final readonly class ResolveScheduledEvent
{
    public function __construct(
        public int $eventId,
    ) {}
}
