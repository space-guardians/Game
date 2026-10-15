<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameEvent;
use App\Enum\Event\GameEventStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameEvent>
 */
final class GameEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameEvent::class);
    }

    /** @return list<GameEvent> événements en cours, par fin la plus proche */
    public function findRunning(): array
    {
        /** @var list<GameEvent> */
        return $this->createQueryBuilder('e')
            ->where('e.status = :running')
            ->setParameter('running', GameEventStatus::Running)
            ->orderBy('e.endsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
