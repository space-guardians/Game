<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\BuildingQueueItem;
use App\Entity\ScheduledEvent;
use App\Enum\Notification\NotificationType;
use App\Service\Notification\Notifications;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fin d'une construction : la production d'avant est consolidée jusqu'à l'heure de fin, puis le bâtiment passe au
 * niveau visé et la construction quitte la file. Appelé par le résolveur, sous verrou et dans une transaction.
 */
final readonly class BuildingCompletedHandler implements ScheduledEventHandler
{
    public const string TYPE = 'building.completed';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlanetResources $resources,
        private Notifications $notifications,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public function handle(ScheduledEvent $event): void
    {
        $item = $this->entityManager->find(BuildingQueueItem::class, $event->getPayload()['item'] ?? null);
        if (!$item instanceof BuildingQueueItem) {
            // Construction annulée entre-temps (#22) : rien à terminer
            return;
        }

        $planet = $item->getPlanet();
        $this->resources->settle($planet, $item->getEndsAt());
        $planet->setBuildingLevel($item->getType(), $item->getTargetLevel());
        $this->entityManager->remove($item);
        $owner = $planet->getOwner();
        if (null !== $owner) {
            $this->notifications->notify($owner, NotificationType::BuildingCompleted, \sprintf('%s niveau %d achevé sur %s.', $item->getType()->getName(), $item->getTargetLevel(), $planet), 'app_buildings');
        }
    }
}
