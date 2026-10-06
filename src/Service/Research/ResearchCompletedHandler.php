<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\ResearchQueueItem;
use App\Entity\ScheduledEvent;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fin d'une recherche : la technologie passe au niveau visé pour tout l'empire et la recherche quitte la file.
 * Appelé par le résolveur, sous verrou de la planète de lancement et dans une transaction.
 */
final readonly class ResearchCompletedHandler implements ScheduledEventHandler
{
    public const string TYPE = 'research.completed';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public function handle(ScheduledEvent $event): void
    {
        $item = $this->entityManager->find(ResearchQueueItem::class, $event->getPayload()['item'] ?? null);
        if (!$item instanceof ResearchQueueItem) {
            // Recherche annulée entre-temps (#27) : rien à terminer
            return;
        }

        $item->getEmpire()->setResearchLevel($item->getTechnology(), $item->getTargetLevel());
        $this->entityManager->remove($item);
    }
}
