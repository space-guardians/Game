<?php

declare(strict_types=1);

namespace App\Service\Alliance;

use App\Entity\Alliance;
use App\Entity\AlliancePlanet;
use App\Entity\Fleet;
use App\Model\Scheduling\GameTopics;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;

/**
 * Alertes du scanner d'alliance (§4.10) : chaque membre de l'alliance est prévenu en temps réel d'un trajet repéré,
 * une fois le départ validé (ScannerAlertNotifier), jamais s'il a échoué.
 */
final class ScannerAlerts implements ResetInterface
{
    /** @var list<array{alliance: Alliance, planet: string, empire: string, ships: int, destination: string, arrivesAt: \DateTimeImmutable}> */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function detected(AlliancePlanet $alliancePlanet, Fleet $fleet, string $destination, \DateTimeImmutable $arrivesAt): void
    {
        $this->pending[] = [
            'alliance' => $alliancePlanet->getAlliance(),
            'planet' => $alliancePlanet->getName(),
            'empire' => $fleet->getEmpire()->getName(),
            'ships' => $fleet->shipCount(),
            'destination' => $destination,
            'arrivesAt' => $arrivesAt,
        ];
    }

    public function hasPending(): bool
    {
        return [] !== $this->pending;
    }

    public function notify(): void
    {
        $alerts = $this->pending;
        $this->pending = [];
        // Transaction annulée (départ refusé en cours de route) : rien à annoncer
        if (!$this->entityManager->isOpen()) {
            return;
        }
        foreach ($alerts as $alert) {
            // Une seule publication, sur le topic de l'alliance, que chaque membre reçoit (§5.2)
            try {
                $this->hub->publish(new Update(GameTopics::alliance($alert['alliance']), $this->twig->render('streams/scanner_alert.stream.html.twig', $alert), private: true));
            } catch (\Throwable $exception) {
                $this->logger->warning('Alerte du scanner d’alliance impossible à publier.', ['exception' => $exception]);
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
