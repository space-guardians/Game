<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Event\ScheduledEventResolved;
use App\Model\Scheduling\GameTopics;
use App\Repository\TechnologyRepository;
use App\Service\Research\ResearchCompletedHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Fin d'une recherche (§4.4) : le joueur reçoit sur son topic privé /empire/{id} un Turbo Stream qui affiche une
 * notification et rafraîchit la page, comme pour une construction.
 */
#[AsEventListener]
final readonly class ResearchCompletionNotifier
{
    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
        private TechnologyRepository $technologies,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ScheduledEventResolved $resolved): void
    {
        $event = $resolved->event;
        $owner = $event->getPlanet()?->getOwner();
        if (ResearchCompletedHandler::TYPE !== $event->getType() || ScheduledEventStatus::Done !== $event->getStatus() || null === $owner) {
            return;
        }
        $payload = $event->getPayload();
        $technology = \is_string($payload['technology'] ?? null) ? $this->technologies->findOneByCode($payload['technology']) : null;

        try {
            $this->hub->publish(new Update(
                GameTopics::empire($owner),
                $this->twig->render('streams/research_completed.stream.html.twig', [
                    'technology' => $technology?->getName() ?? 'Recherche',
                    'level' => $payload['level'] ?? null,
                ]),
                private: true,
            ));
        } catch (\Throwable $exception) {
            // La recherche est terminée : sans hub, le compte à rebours de la page prend le relais
            $this->logger->warning('Notification de fin de recherche impossible (événement #{id}).', ['id' => $event->getId(), 'exception' => $exception]);
        }
    }
}
