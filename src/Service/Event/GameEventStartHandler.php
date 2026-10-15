<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\GameEvent;
use App\Entity\ScheduledEvent;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Début d'un événement de jeu (§4.16) : il passe en cours et les joueurs reçoivent l'annonce. Sans effet si
 * l'événement a été annulé ou replanifié entre-temps (date du réveil différente de celle de l'événement).
 */
final readonly class GameEventStartHandler implements ScheduledEventHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameEvents $events,
    ) {}

    public static function type(): string
    {
        return GameEvents::START;
    }

    public function handle(ScheduledEvent $event): void
    {
        $gameEvent = $this->entityManager->find(GameEvent::class, $event->getPayload()['event'] ?? null);
        if ($gameEvent instanceof GameEvent && ($event->getPayload()['at'] ?? null) === $gameEvent->getStartsAt()->format(\DATE_ATOM)) {
            $this->events->start($gameEvent);
        }
    }
}
