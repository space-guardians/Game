<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\ScheduledEvent;
use App\Entity\ShipyardOrder;
use App\Service\Scheduling\ScheduledEventHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Livraison d'une commande du chantier spatial : les vaisseaux rejoignent l'inventaire de la planète et la commande
 * quitte la file. Appelé par le résolveur, sous verrou de la planète et dans une transaction.
 */
final readonly class ShipyardOrderCompletedHandler implements ScheduledEventHandler
{
    public const string TYPE = 'shipyard.completed';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public function handle(ScheduledEvent $event): void
    {
        $order = $this->entityManager->find(ShipyardOrder::class, $event->getPayload()['order'] ?? null);
        if (!$order instanceof ShipyardOrder) {
            return;
        }

        $order->getPlanet()->addShips($order->getType(), $order->getQuantity());
        $this->entityManager->remove($order);
    }
}
