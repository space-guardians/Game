<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Model\Scheduling\GameTopics;
use App\Repository\BuildingTypeRepository;
use App\Service\Economy\BuildingCompletedHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Premier flux Mercure du jeu (§5.2) : à la fin d'une construction, le joueur reçoit sur son topic privé
 * /empire/{id} un Turbo Stream qui affiche une notification et rafraîchit la page (niveaux, ressources, file).
 */
#[AsEventListener]
final readonly class BuildingCompletionNotifier
{
    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
        private BuildingTypeRepository $buildingTypes,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ScheduledEventResolved $resolved): void
    {
        $event = $resolved->event;
        $owner = $event->getPlanet()?->getOwner();
        if (BuildingCompletedHandler::TYPE !== $event->getType() || ScheduledEventStatus::Done !== $event->getStatus() || null === $owner) {
            return;
        }
        $payload = $event->getPayload();
        $type = \is_string($payload['building'] ?? null) ? $this->buildingTypes->findOneByCode($payload['building']) : null;

        try {
            $this->hub->publish(new Update(
                GameTopics::empire($owner),
                $this->twig->render('streams/building_completed.stream.html.twig', [
                    'building' => $type?->getName() ?? 'Construction',
                    'level' => $payload['level'] ?? null,
                    'planet' => $event->getPlanet(),
                    'empire' => $owner,
                ]),
                private: true,
            ));
        } catch (\Throwable $exception) {
            // La construction est terminée : sans hub, le compte à rebours de la page prend le relais
            $this->logger->warning('Notification de fin de construction impossible (événement #{id}).', ['id' => $event->getId(), 'exception' => $exception]);
        }
    }
}
