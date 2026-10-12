<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Enum\Fleet\FleetStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fleet>
 */
final class FleetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fleet::class);
    }

    /** @return list<Fleet> */
    public function findOwnedBy(Empire $empire): array
    {
        /** @var list<Fleet> */
        return $this->findBy(['empire' => $empire], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function countOwnedBy(Empire $empire): int
    {
        return $this->count(['empire' => $empire]);
    }

    /**
     * Flottes en panne des empires donnés (alliés à ravitailler).
     *
     * @param list<Empire> $empires
     *
     * @return list<Fleet>
     */
    public function findStrandedOf(array $empires): array
    {
        if ([] === $empires) {
            return [];
        }

        /** @var list<Fleet> */
        return $this->createQueryBuilder('f')
            ->andWhere('f.status = :stranded')
            ->andWhere('f.empire IN (:empires)')
            ->setParameter('stranded', FleetStatus::Stranded)
            ->setParameter('empires', $empires)
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Flottes stationnées au niveau d'un système (ni planète ni point précis) : elles gardent le système (§4.6.2).
     *
     * @return list<Fleet>
     */
    public function findGuarding(int $systemId): array
    {
        /** @var list<Fleet> */
        return $this->createQueryBuilder('f')
            ->andWhere('f.status = :stationed')
            ->andWhere('f.location.systemId = :system')
            ->andWhere('f.location.planetId IS NULL')
            ->andWhere('f.location.localX IS NULL')
            ->setParameter('stationed', FleetStatus::Stationed)
            ->setParameter('system', $systemId)
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Flottes à l'arrêt (stationnées ou en panne) d'une galaxie ; le lieu exact est comparé par l'appelant.
     *
     * @return list<Fleet>
     */
    public function findStoppedInGalaxy(int $galaxyId): array
    {
        /** @var list<Fleet> */
        return $this->createQueryBuilder('f')
            ->andWhere('f.status != :inFlight')
            ->andWhere('f.location.galaxyId = :galaxy')
            ->setParameter('inFlight', FleetStatus::InFlight)
            ->setParameter('galaxy', $galaxyId)
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
