<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResolveDueEvents;
use App\Repository\ScheduledEventRepository;
use App\Service\Scheduling\ScheduledEventResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Filet de sécurité : résout les événements échus dont le réveil différé s'est perdu (transport vidé, worker
 * arrêté au mauvais moment). Par lots, pour ne pas bloquer le planning.
 */
#[AsMessageHandler]
final readonly class ResolveDueEventsHandler
{
    public const int BATCH = 200;

    public function __construct(
        private ScheduledEventRepository $events,
        private ScheduledEventResolver $resolver,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ResolveDueEvents $message): void
    {
        foreach ($this->events->findDueIds($this->clock->now(), self::BATCH) as $id) {
            $this->resolver->resolve($id);
        }
    }
}
