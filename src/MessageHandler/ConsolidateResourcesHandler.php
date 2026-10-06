<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ConsolidateResources;
use App\Repository\PlanetRepository;
use App\Service\Economy\PlanetResources;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consolide, par lots, le stock des planètes habitées non mises à jour depuis STALE_AFTER. Chaque planète est
 * consolidée sous le verrou de ses événements planifiés, pour ne pas croiser une résolution en cours.
 */
#[AsMessageHandler]
final readonly class ConsolidateResourcesHandler
{
    public const string STALE_AFTER = '-1 hour';
    public const int BATCH = 200;

    public function __construct(
        private PlanetRepository $planets,
        private PlanetResources $resources,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ConsolidateResources $message): void
    {
        $staleBefore = $this->clock->now()->modify(self::STALE_AFTER);
        $afterId = 0;

        do {
            $planets = $this->planets->findStaleResources($staleBefore, $afterId, self::BATCH);
            foreach ($planets as $planet) {
                $afterId = (int) $planet->getId();
                $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey($afterId), ttl: 30.0);
                if (!$lock->acquire()) {
                    // Résolution en cours sur cette planète : elle sera consolidée au prochain passage
                    continue;
                }
                try {
                    $this->resources->settle($planet);
                    $this->entityManager->flush();
                } finally {
                    $lock->release();
                }
            }
            $this->entityManager->clear();
        } while (\count($planets) === self::BATCH);
    }
}
