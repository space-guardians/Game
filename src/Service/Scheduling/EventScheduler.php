<?php

declare(strict_types=1);

namespace App\Service\Scheduling;

use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Message\ResolveScheduledEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Planifie un événement de jeu : il est enregistré (avec les modifications en cours, dans le même flush), puis un
 * réveil différé est envoyé au worker pour son échéance (§5.2).
 */
final readonly class EventScheduler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function schedule(string $type, \DateTimeImmutable $dueAt, ?Planet $planet = null, array $payload = []): ScheduledEvent
    {
        $now = $this->clock->now();
        $event = new ScheduledEvent($type, $dueAt, $now, $planet, $payload);
        $this->entityManager->persist($event);
        // Enregistré avant l'envoi du réveil : le worker doit trouver l'événement en base
        $this->entityManager->flush();

        $this->wake($event, $now);

        return $event;
    }

    /** Envoie (ou renvoie) le réveil d'un événement pour son échéance */
    public function wake(ScheduledEvent $event, ?\DateTimeImmutable $now = null): void
    {
        $now ??= $this->clock->now();
        $delayMs = (int) ceil(max(0.0, (float) $event->getDueAt()->format('U.u') - (float) $now->format('U.u')) * 1000);

        $this->bus->dispatch(new ResolveScheduledEvent((int) $event->getId()), $delayMs > 0 ? [new DelayStamp($delayMs)] : []);
    }
}
