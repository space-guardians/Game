<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Model\Scheduling\GameTopics;
use App\Repository\ShipTypeRepository;
use App\Service\Fleet\ShipyardOrderCompletedHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Livraison d'une commande du chantier spatial (§4.5) : le joueur reçoit sur son topic privé /empire/{id} un Turbo
 * Stream qui affiche une notification et rafraîchit la page, comme pour une construction.
 */
#[AsEventListener]
final readonly class ShipyardDeliveryNotifier
{
    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
        private ShipTypeRepository $shipTypes,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ScheduledEventResolved $resolved): void
    {
        $event = $resolved->event;
        $owner = $event->getPlanet()?->getOwner();
        if (ShipyardOrderCompletedHandler::TYPE !== $event->getType() || ScheduledEventStatus::Done !== $event->getStatus() || null === $owner) {
            return;
        }
        $payload = $event->getPayload();
        $type = \is_string($payload['ship'] ?? null) ? $this->shipTypes->findOneByCode($payload['ship']) : null;

        try {
            $this->hub->publish(new Update(
                GameTopics::empire($owner),
                $this->twig->render('streams/shipyard_completed.stream.html.twig', [
                    'ship' => $type?->getName() ?? 'vaisseaux',
                    'quantity' => \is_int($payload['quantity'] ?? null) ? $payload['quantity'] : 0,
                    'planet' => $event->getPlanet(),
                    'empire' => $owner,
                ]),
                private: true,
            ));
        } catch (\Throwable $exception) {
            // Les vaisseaux sont livrés : sans hub, le compte à rebours de la page prend le relais
            $this->logger->warning('Notification de livraison du chantier spatial impossible (événement #{id}).', ['id' => $event->getId(), 'exception' => $exception]);
        }
    }
}
