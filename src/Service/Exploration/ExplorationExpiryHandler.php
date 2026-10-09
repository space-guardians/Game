<?php

declare(strict_types=1);

namespace App\Service\Exploration;

use App\Entity\ScheduledEvent;
use App\Service\Scheduling\ScheduledEventHandler;

/**
 * Échéance d'une quête à choix (§4.6.4) : sans décision du joueur, l'occasion passe et la flotte reprend ses ordres.
 */
final readonly class ExplorationExpiryHandler implements ScheduledEventHandler
{
    public const string TYPE = 'exploration.expiry';

    public function __construct(
        private Explorations $explorations,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public function handle(ScheduledEvent $event): void
    {
        $this->explorations->expire((int) ($event->getPayload()['event'] ?? 0), $event->getDueAt());
    }
}
