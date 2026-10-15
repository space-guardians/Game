<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\GameEvent;
use App\Entity\ScheduledEvent;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fin d'un événement de jeu (§4.16) : ses effets cessent. Sans effet si l'événement a été replanifié entre-temps.
 */
final readonly class GameEventEndHandler implements ScheduledEventHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameEvents $events,
    ) {}

    public static function type(): string
    {
        return GameEvents::END;
    }

    public function handle(ScheduledEvent $event): void
    {
        $gameEvent = $this->entityManager->find(GameEvent::class, $event->getPayload()['event'] ?? null);
        if ($gameEvent instanceof GameEvent && ($event->getPayload()['at'] ?? null) === $gameEvent->getEndsAt()->format(\DATE_ATOM)) {
            $this->events->finish($gameEvent);
        }
    }
}
