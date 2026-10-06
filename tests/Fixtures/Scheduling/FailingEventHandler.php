<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Scheduling;

use App\Entity\ScheduledEvent;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestionnaire de test : modifie la planète puis échoue, pour vérifier que ses modifications sont annulées.
 */
final readonly class FailingEventHandler implements ScheduledEventHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public static function type(): string
    {
        return 'test.fail';
    }

    public function handle(ScheduledEvent $event): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE planet SET temperature = temperature + 100 WHERE id = ?', [$event->getPlanet()?->getId()]);

        throw new \RuntimeException('Résolution impossible.');
    }
}
