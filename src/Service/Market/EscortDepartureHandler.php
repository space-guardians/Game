<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\PlayerQuest;
use App\Entity\ScheduledEvent;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Départ d'un convoi escorté à l'heure du contrat (§4.15).
 */
final readonly class EscortDepartureHandler implements ScheduledEventHandler
{
    public const string TYPE = 'escort.departure';

    public function __construct(
        private Escorts $escorts,
        private EntityManagerInterface $entityManager,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public function handle(ScheduledEvent $event): void
    {
        $quest = $this->entityManager->find(PlayerQuest::class, $event->getPayload()['quest'] ?? 0);
        if ($quest instanceof PlayerQuest) {
            $this->escorts->depart($quest, $event->getDueAt());
        }
    }
}
