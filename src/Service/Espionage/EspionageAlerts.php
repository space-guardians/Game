<?php

declare(strict_types=1);

namespace App\Service\Espionage;

use App\Entity\Empire;
use App\Model\Scheduling\GameTopics;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;

/**
 * Alertes de sondes repérées (§4.9) : l'empire espionné est prévenu en temps réel, une fois la résolution de l'arrivée
 * validée (EspionageAlertNotifier), jamais si elle a échoué.
 */
final class EspionageAlerts implements ResetInterface
{
    /** @var list<array{target: Empire, spy: string, location: string}> */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    public function detected(Empire $target, Empire $spy, string $locationLabel): void
    {
        $this->pending[] = ['target' => $target, 'spy' => $spy->getName(), 'location' => $locationLabel];
    }

    public function notify(): void
    {
        $alerts = $this->pending;
        $this->pending = [];
        foreach ($alerts as $alert) {
            try {
                $this->hub->publish(new Update(
                    GameTopics::empire($alert['target']),
                    $this->twig->render('streams/probes_detected.stream.html.twig', ['spy' => $alert['spy'], 'location' => $alert['location']]),
                    private: true,
                ));
            } catch (\Throwable $exception) {
                $this->logger->warning('Alerte de sondes repérées impossible à publier.', ['exception' => $exception]);
            }
        }
    }

    public function discard(): void
    {
        $this->pending = [];
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
