<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\GameEvent;
use App\Entity\Planet;
use App\Enum\Event\GameEventStatus;
use App\Repository\GameEventRepository;
use App\Service\Messaging\SystemMessenger;
use App\Service\Scheduling\EventScheduler;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Événements de jeu (§4.16) : planification de leur début et de leur fin (événements `game_event.start` /
 * `game_event.end`, vérifiés contre les dates de l'événement, donc sans effet s'il a été replanifié ou annulé), annonce
 * aux joueurs par message système, et effets des événements en cours sur une planète (multiplicateurs : production,
 * vitesse de construction et de recherche, butin d'exploration). Les événements en cours sont lus une fois par
 * requête.
 */
final class GameEvents implements ResetInterface
{
    public const string START = 'game_event.start';

    public const string END = 'game_event.end';

    /** @var list<GameEvent>|null */
    private ?array $running = null;

    public function __construct(
        private readonly GameEventRepository $events,
        private readonly EventScheduler $scheduler,
        private readonly SystemMessenger $messenger,
        private readonly ClockInterface $clock,
    ) {}

    /** Programme le début et la fin d'un événement (à sa création ou après modification de ses dates) */
    public function schedule(GameEvent $event): void
    {
        $payload = ['event' => $event->getId()];
        $this->scheduler->schedule(self::START, $event->getStartsAt(), null, $payload + ['at' => $event->getStartsAt()->format(\DATE_ATOM)]);
        $this->scheduler->schedule(self::END, $event->getEndsAt(), null, $payload + ['at' => $event->getEndsAt()->format(\DATE_ATOM)]);
    }

    /** Début : l'événement passe en cours et les joueurs sont prévenus */
    public function start(GameEvent $event): void
    {
        if (GameEventStatus::Scheduled !== $event->getStatus()) {
            return;
        }
        $event->start();
        $this->running = null;
        $this->messenger->toAll(
            \sprintf('Événement : %s', $event->getName()),
            trim(\sprintf("%s\n\nEffets : %s. Portée : %s. Jusqu’au %s.", $event->getDescription(), $event->effectsLabel(), mb_lcfirst($event->getScope()->label()), $event->getEndsAt()->format('d/m/Y à H:i'))),
        );
    }

    public function finish(GameEvent $event): void
    {
        $event->finish();
        $this->running = null;
    }

    public function cancel(GameEvent $event): void
    {
        $event->cancel();
        $this->running = null;
    }

    /**
     * Événements en cours qui s'appliquent à cette planète.
     *
     * @return list<GameEvent>
     */
    public function covering(Planet $planet): array
    {
        $now = $this->clock->now();

        return array_values(array_filter($this->running(), static fn(GameEvent $event): bool => $event->isRunningAt($now) && $event->covers($planet)));
    }

    /** Multiplicateur de production d'une planète (1 sans événement) */
    public function production(Planet $planet): float
    {
        return $this->multiplier($planet, static fn(GameEvent $event): int => $event->getProductionBonus());
    }

    /** Multiplicateur de vitesse de construction (bâtiments) */
    public function construction(Planet $planet): float
    {
        return $this->multiplier($planet, static fn(GameEvent $event): int => $event->getConstructionBonus());
    }

    /** Multiplicateur de vitesse de recherche, d'après la planète qui la lance */
    public function research(Planet $planet): float
    {
        return $this->multiplier($planet, static fn(GameEvent $event): int => $event->getResearchBonus());
    }

    /** Multiplicateur des gains d'exploration, d'après la planète d'origine de la flotte */
    public function explorationLoot(Planet $planet): float
    {
        return $this->multiplier($planet, static fn(GameEvent $event): int => $event->getExplorationBonus());
    }

    public function reset(): void
    {
        $this->running = null;
    }

    /** @param \Closure(GameEvent): int $bonus */
    private function multiplier(Planet $planet, \Closure $bonus): float
    {
        $total = 0;
        foreach ($this->covering($planet) as $event) {
            $total += $bonus($event);
        }

        return 1.0 + $total / 100;
    }

    /** @return list<GameEvent> */
    private function running(): array
    {
        return $this->running ??= $this->events->findRunning();
    }
}
