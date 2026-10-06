<?php

declare(strict_types=1);

namespace App\Service\Scheduling;

use App\Entity\ScheduledEvent;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Repository\ScheduledEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Résout les événements planifiés arrivés à échéance (§5.2) :
 * - sous un verrou par planète, pour qu'un événement ne soit jamais résolu deux fois et que ceux d'une même
 *   planète le soient l'un après l'autre, dans l'ordre de leurs échéances ;
 * - chacun dans sa transaction : un échec annule les modifications de son gestionnaire et le marque en échec ;
 * - puis annonce la résolution (ScheduledEventResolved), dont les écouteurs informent le joueur via Mercure.
 */
final readonly class ScheduledEventResolver
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private ScheduledEventRepository $events,
        #[AutowireLocator(ScheduledEventHandler::TAG, defaultIndexMethod: 'type')]
        private ContainerInterface $handlers,
        private LockFactory $lockFactory,
        private EventDispatcherInterface $dispatcher,
        private EventScheduler $scheduler,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Résout l'événement s'il est échu, ainsi que les autres événements échus de sa planète, plus anciens d'abord.
     *
     * @return int nombre d'événements résolus (avec succès ou en échec)
     */
    public function resolve(int $eventId): int
    {
        $event = $this->entityManager()->find(ScheduledEvent::class, $eventId);
        if (!$event instanceof ScheduledEvent || ScheduledEventStatus::Pending !== $event->getStatus()) {
            // Déjà résolu, annulé ou supprimé : le réveil n'a plus rien à faire
            return 0;
        }
        if ($event->getDueAt() > $this->clock->now()) {
            // Réveil en avance (précision du transport) : on le renvoie pour l'échéance
            $this->scheduler->wake($event);

            return 0;
        }

        $planet = $event->getPlanet();
        $lock = $this->lockFactory->createLock(null === $planet ? 'scheduled-event-' . $eventId : self::planetLockKey((int) $planet->getId()), ttl: 60.0);
        $lock->acquire(true);

        try {
            $ids = null === $planet
                ? [$eventId]
                : array_map(static fn(ScheduledEvent $due): int => (int) $due->getId(), $this->events->findDueForPlanet($planet, $this->clock->now()));
            $resolved = 0;
            foreach ($ids as $id) {
                $resolved += $this->resolveOne($id) ? 1 : 0;
            }

            return $resolved;
        } finally {
            $lock->release();
        }
    }

    /** Verrou d'une planète : résolution de ses événements, consolidation de ses ressources */
    public static function planetLockKey(int $planetId): string
    {
        return 'scheduled-events-planet-' . $planetId;
    }

    /** Sous verrou : relit l'événement (un autre processus a pu le résoudre entre-temps) et le résout */
    private function resolveOne(int $eventId): bool
    {
        $entityManager = $this->entityManager();
        $event = $entityManager->find(ScheduledEvent::class, $eventId);
        // Statut relu en base : l'entité en mémoire a pu être chargée avant qu'un autre processus la résolve
        if (!$event instanceof ScheduledEvent || ScheduledEventStatus::Pending !== $this->events->currentStatus($eventId) || !$event->isDue($this->clock->now())) {
            return false;
        }

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            if (!$this->handlers->has($event->getType())) {
                throw new \LogicException(\sprintf('Aucun gestionnaire pour les événements « %s ».', $event->getType()));
            }
            $handler = $this->handlers->get($event->getType());
            \assert($handler instanceof ScheduledEventHandler);
            $handler->handle($event);
            $event->markDone($this->clock->now());
            $entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->logger->error('Échec de la résolution de l\'événement planifié #{id} ({type}).', ['id' => $eventId, 'type' => $event->getType(), 'exception' => $exception]);
            $event = $this->markFailed($eventId, $exception);
        }

        $this->announce($event);

        return true;
    }

    /** Les modifications du gestionnaire sont abandonnées : on repart d'un gestionnaire d'entités vierge */
    private function markFailed(int $eventId, \Throwable $exception): ScheduledEvent
    {
        $entityManager = $this->entityManager();
        if ($entityManager->isOpen()) {
            $entityManager->clear();
        } else {
            $this->doctrine->resetManager();
            $entityManager = $this->entityManager();
        }

        $event = $entityManager->find(ScheduledEvent::class, $eventId);
        \assert($event instanceof ScheduledEvent);
        $event->markFailed($exception->getMessage(), $this->clock->now());
        $entityManager->flush();

        return $event;
    }

    /** Après validation : les écouteurs informent le joueur ; leur échec n'annule pas la résolution */
    private function announce(ScheduledEvent $event): void
    {
        try {
            $this->dispatcher->dispatch(new ScheduledEventResolved($event));
        } catch (\Throwable $exception) {
            $this->logger->warning('Notification impossible après l\'événement #{id}.', ['id' => $event->getId(), 'exception' => $exception]);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->doctrine->getManagerForClass(ScheduledEvent::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
