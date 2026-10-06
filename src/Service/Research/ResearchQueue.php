<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\Empire;
use App\Entity\Planet;
use App\Entity\ResearchQueueItem;
use App\Entity\Technology;
use App\Enum\Economy\BuildingEffect;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Research\MissingPrerequisites;
use App\Exception\Research\ResearchInProgress;
use App\Model\Economy\EconomySettings;
use App\Repository\PlanetRepository;
use App\Repository\ResearchQueueItemRepository;
use App\Service\Economy\PlanetResources;
use App\Service\Scheduling\EventScheduler;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Lance la recherche du niveau suivant d'une technologie (§4.4) : une seule à la fois par empire, depuis n'importe
 * laquelle de ses planètes, qui paie le coût (après consolidation de ses ressources). La durée dépend de la somme des
 * laboratoires de l'empire ; la fin est planifiée comme événement de jeu (ResearchCompletedHandler).
 * Sous le verrou de l'empire (une seule recherche) puis de la planète (son stock).
 */
final readonly class ResearchQueue
{
    public function __construct(
        private ResearchQueueItemRepository $queue,
        private PlanetRepository $planets,
        private PlanetResources $resources,
        private ResearchRules $rules,
        private PrerequisiteChecker $prerequisites,
        private EconomySettings $settings,
        private EventScheduler $scheduler,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
    ) {}

    public static function empireLockKey(int $empireId): string
    {
        return 'research-empire-' . $empireId;
    }

    /**
     * @throws ResearchInProgress
     * @throws MissingPrerequisites
     * @throws InsufficientResources
     */
    public function start(Planet $planet, Technology $technology): ResearchQueueItem
    {
        $empire = $planet->getOwner() ?? throw new \DomainException(\sprintf('La planète %s n\'appartient à aucun empire.', $planet));
        $empireLock = $this->lockFactory->createLock(self::empireLockKey((int) $empire->getId()), ttl: 30.0);
        $empireLock->acquire(true);
        $planetLock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: 30.0);
        $planetLock->acquire(true);

        try {
            $current = $this->queue->findActiveFor($empire);
            if (null !== $current) {
                throw new ResearchInProgress($current);
            }
            $missing = $this->prerequisites->missingForResearch($technology, $empire);
            if ([] !== $missing) {
                throw new MissingPrerequisites($missing);
            }

            $targetLevel = $empire->researchLevel($technology) + 1;
            $cost = $this->rules->cost($technology, $targetLevel);
            $snapshot = $this->resources->settle($planet);
            if (!$snapshot->amounts->covers($cost)) {
                throw new InsufficientResources($snapshot->amounts->shortfall($cost));
            }

            $startedAt = $snapshot->at;
            $endsAt = $startedAt->modify(\sprintf('+%d seconds', $this->duration($empire, $technology, $targetLevel)));

            return $this->entityManager->wrapInTransaction(function () use ($empire, $planet, $technology, $targetLevel, $cost, $snapshot, $startedAt, $endsAt): ResearchQueueItem {
                $planet->storeResources($snapshot->amounts->minus($cost), $startedAt);
                $item = new ResearchQueueItem($empire, $planet, $technology, $targetLevel, $cost, $startedAt, $endsAt);
                $this->entityManager->persist($item);
                $this->entityManager->flush();
                // Code et niveau recopiés : la recherche quitte la file à sa fin, la notification en a besoin ensuite
                $item->attachEvent($this->scheduler->schedule(ResearchCompletedHandler::TYPE, $endsAt, $planet, [
                    'item' => $item->getId(),
                    'technology' => $technology->getCode(),
                    'level' => $targetLevel,
                ]));
                $this->entityManager->flush();

                return $item;
            });
        } finally {
            $planetLock->release();
            $empireLock->release();
        }
    }

    /** Durée de recherche du niveau visé, selon les laboratoires de tout l'empire */
    public function duration(Empire $empire, Technology $technology, int $targetLevel): int
    {
        return $this->rules->durationSeconds($this->rules->cost($technology, $targetLevel), $this->laboratoryLevels($empire), $this->settings->universeSpeed);
    }

    /** Somme des niveaux de tous les laboratoires de l'empire, toutes planètes confondues (§4.4) */
    public function laboratoryLevels(Empire $empire): int
    {
        $levels = 0;
        foreach ($this->planets->findOwnedBy($empire) as $planet) {
            foreach ($planet->getBuildings() as $building) {
                if (BuildingEffect::ResearchLab === $building->getType()->getEffect()) {
                    $levels += $building->getLevel();
                }
            }
        }

        return $levels;
    }
}
