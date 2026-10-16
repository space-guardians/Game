<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ScheduledEventRepository;
use App\Service\Admin\AdminIndicators;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Santé de l'application pour la supervision (docs/exploitation.md) : sonde des conteneurs et surveillance externe.
 * Répond 200 si tout va bien, 503 sinon, avec l'état de chaque dépendance. Le worker est jugé à ses effets : des
 * événements planifiés en retard de plus d'AdminIndicators::LATE_AFTER secondes signalent un worker arrêté ou
 * débordé. Hors pare-feu (security.yaml) : ni session ni compte, aucune donnée de jeu.
 */
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'app.redis')]
        private readonly \Redis $redis,
        private readonly ScheduledEventRepository $events,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/sante', name: 'app_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn(): bool => 1 === (int) $this->connection->fetchOne('SELECT 1')),
            'redis' => $this->check(fn(): bool => false !== $this->redis->ping()),
        ];
        $checks['worker'] = 'ok' !== $checks['database'] ? 'unknown' : $this->check(
            fn(): bool => 0 === $this->events->countLate($this->clock->now()->modify(\sprintf('-%d seconds', AdminIndicators::LATE_AFTER))),
            'late',
        );
        $healthy = [] === array_filter($checks, static fn(string $state): bool => 'ok' !== $state);

        $response = new JsonResponse(['status' => $healthy ? 'ok' : 'error', 'checks' => $checks], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /** @param \Closure(): bool $probe */
    private function check(\Closure $probe, string $failure = 'error'): string
    {
        try {
            return $probe() ? 'ok' : $failure;
        } catch (\Throwable) {
            return 'error';
        }
    }
}
