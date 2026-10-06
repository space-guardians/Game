<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResolveScheduledEvent;
use App\Service\Scheduling\ScheduledEventResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ResolveScheduledEventHandler
{
    public function __construct(
        private ScheduledEventResolver $resolver,
    ) {}

    public function __invoke(ResolveScheduledEvent $message): void
    {
        $this->resolver->resolve($message->eventId);
    }
}
