<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Scheduling;

use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestionnaire de test : note l'ordre des résolutions et réchauffe la planète d'un degré (modification à valider).
 */
final class RecordingEventHandler implements ScheduledEventHandler
{
    /** @var list<int> */
    public array $handled = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public static function type(): string
    {
        return 'test.record';
    }

    public function handle(ScheduledEvent $event): void
    {
        $this->handled[] = (int) $event->getId();
        $planet = $event->getPlanet();
        if ($planet instanceof Planet) {
            $this->entityManager->getConnection()->executeStatement('UPDATE planet SET temperature = temperature + 1 WHERE id = ?', [$planet->getId()]);
        }
    }
}
