<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Battle;
use App\Entity\BattleParticipant;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Enum\Combat\BattleStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Battle>
 */
final class BattleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Battle::class);
    }

    /**
     * Batailles programmées d'une galaxie, les plus proches d'abord (le lieu exact est comparé par l'appelant).
     *
     * @return list<Battle>
     */
    public function findScheduledInGalaxy(int $galaxyId): array
    {
        /** @var list<Battle> */
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :scheduled')
            ->andWhere('b.location.galaxyId = :galaxy')
            ->setParameter('scheduled', BattleStatus::Scheduled)
            ->setParameter('galaxy', $galaxyId)
            ->orderBy('b.resolvesAt', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Bataille programmée qu'une flotte va rejoindre ou attend sur place */
    public function findScheduledFor(Fleet $fleet): ?Battle
    {
        /** @var Battle|null */
        return $this->createQueryBuilder('b')
            ->join('b.participants', 'p')
            ->andWhere('p.fleet = :fleet')
            ->andWhere('b.status = :scheduled')
            ->setParameter('fleet', $fleet)
            ->setParameter('scheduled', BattleStatus::Scheduled)
            ->orderBy('b.resolvesAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Batailles programmées où se trouvent les flottes d'un empire, par identifiant de flotte.
     *
     * @return array<int, Battle>
     */
    public function findScheduledByFleet(Empire $empire): array
    {
        /** @var list<BattleParticipant> $participants */
        $participants = $this->getEntityManager()->createQueryBuilder()
            ->select('p', 'b')
            ->from(BattleParticipant::class, 'p')
            ->join('p.battle', 'b')
            ->andWhere('p.empire = :empire')
            ->andWhere('p.fleet IS NOT NULL')
            ->andWhere('b.status = :scheduled')
            ->setParameter('empire', $empire)
            ->setParameter('scheduled', BattleStatus::Scheduled)
            ->getQuery()
            ->getResult();
        $battles = [];
        foreach ($participants as $participant) {
            $battles[(int) $participant->getFleet()?->getId()] = $participant->getBattle();
        }

        return $battles;
    }
}
