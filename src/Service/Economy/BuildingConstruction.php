<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\BuildingQueueItem;
use App\Entity\BuildingType;
use App\Entity\Planet;
use App\Enum\Economy\BuildingEffect;
use App\Exception\Economy\ConstructionInProgress;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Economy\NoCancellableConstruction;
use App\Model\Economy\CancellationResult;
use App\Model\Economy\EconomySettings;
use App\Repository\BuildingQueueItemRepository;
use App\Repository\BuildingTypeRepository;
use App\Service\Scheduling\EventScheduler;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Lance ou annule la construction du niveau suivant d'un bâtiment (§4.3) : une seule à la fois par planète, coût
 * débité au lancement (après consolidation des ressources), fin planifiée comme événement de jeu
 * (BuildingCompletedHandler), remboursement au prorata en cas d'annulation.
 * Sous le verrou de la planète, pour ne pas croiser une résolution d'événement ni un double clic.
 */
final readonly class BuildingConstruction
{
    public function __construct(
        private BuildingQueueItemRepository $queue,
        private BuildingTypeRepository $buildingTypes,
        private PlanetResources $resources,
        private BuildingRules $rules,
        private EconomySettings $settings,
        private EventScheduler $scheduler,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private CancellationRefund $refund,
    ) {}

    /**
     * @throws ConstructionInProgress
     * @throws InsufficientResources
     */
    public function start(Planet $planet, BuildingType $type): BuildingQueueItem
    {
        $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: 30.0);
        $lock->acquire(true);

        try {
            $current = $this->queue->findActiveFor($planet);
            if (null !== $current) {
                throw new ConstructionInProgress($current);
            }

            $targetLevel = $planet->buildingLevel($type) + 1;
            $cost = $this->rules->cost($type, $targetLevel);
            $snapshot = $this->resources->settle($planet);
            if (!$snapshot->amounts->covers($cost)) {
                throw new InsufficientResources($snapshot->amounts->shortfall($cost));
            }

            $startedAt = $snapshot->at;
            $endsAt = $startedAt->modify(\sprintf('+%d seconds', $this->duration($planet, $type, $targetLevel)));

            return $this->entityManager->wrapInTransaction(function () use ($planet, $type, $targetLevel, $cost, $snapshot, $startedAt, $endsAt): BuildingQueueItem {
                $planet->storeResources($snapshot->amounts->minus($cost), $startedAt);
                $item = new BuildingQueueItem($planet, $type, $targetLevel, $cost, $startedAt, $endsAt);
                $this->entityManager->persist($item);
                $this->entityManager->flush();
                // Planifié dans la même transaction : le réveil (transport Doctrine) n'existe que si tout est enregistré
                $item->attachEvent($this->scheduler->schedule(BuildingCompletedHandler::TYPE, $endsAt, $planet, ['item' => $item->getId()]));
                $this->entityManager->flush();

                return $item;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Annule la construction en cours (§4.3) : remboursement au prorata du temps restant, plafonné par la capacité
     * de stockage courante ; l'événement de fin est annulé. Une construction déjà échue n'est plus annulable.
     *
     * @throws NoCancellableConstruction
     */
    public function cancel(Planet $planet): CancellationResult
    {
        $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: 30.0);
        $lock->acquire(true);

        try {
            $item = $this->queue->findActiveFor($planet) ?? throw NoCancellableConstruction::none();
            $snapshot = $this->resources->settle($planet);
            $now = $snapshot->at;
            if ($item->getEndsAt() <= $now) {
                throw NoCancellableConstruction::finished();
            }

            $share = $this->refund->remainingShare($item->getStartedAt(), $item->getEndsAt(), $now);
            $refund = $item->getPaid()->times($share);
            $stock = $this->refund->credit($snapshot->amounts, $refund, $snapshot->capacity);

            return $this->entityManager->wrapInTransaction(function () use ($planet, $item, $stock, $now, $share, $refund, $snapshot): CancellationResult {
                $planet->storeResources($stock, $now);
                $item->getEvent()?->cancel($now);
                $this->entityManager->remove($item);
                $this->entityManager->flush();

                $refunded = $stock->minus($snapshot->amounts);

                // Ce qui n'a pas trouvé de place (shortfall : jamais négatif malgré les arrondis)
                return new CancellationResult($share, $refunded, $refunded->shortfall($refund));
            });
        } finally {
            $lock->release();
        }
    }

    /** Durée de construction du niveau visé, selon les usines de la planète */
    public function duration(Planet $planet, BuildingType $type, int $targetLevel): int
    {
        return $this->rules->constructionSeconds(
            $this->rules->cost($type, $targetLevel),
            $this->factoryLevel($planet, BuildingEffect::RobotFactory),
            $this->factoryLevel($planet, BuildingEffect::NaniteFactory),
            $this->settings->universeSpeed,
        );
    }

    private function factoryLevel(Planet $planet, BuildingEffect $effect): int
    {
        $level = 0;
        foreach ($this->buildingTypes->findAllOrdered() as $type) {
            if ($effect === $type->getEffect()) {
                $level += $planet->buildingLevel($type);
            }
        }

        return $level;
    }
}
