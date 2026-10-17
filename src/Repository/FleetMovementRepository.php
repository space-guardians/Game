<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fleet;
use App\Entity\FleetMovement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FleetMovement>
 */
final class FleetMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FleetMovement::class);
    }

    /**
     * Déplacements des escortes portés par celui d'un convoi (§4.15).
     *
     * @return list<FleetMovement>
     */
    public function findCarriedBy(FleetMovement $convoy): array
    {
        return $this->findBy(['carriedBy' => $convoy], ['id' => 'ASC']);
    }

    public function findActiveFor(Fleet $fleet): ?FleetMovement
    {
        return $this->findOneBy(['fleet' => $fleet]);
    }

    /**
     * Déplacements en cours partis d'une galaxie (un trajet y reste).
     *
     * @return list<FleetMovement>
     */
    public function findInGalaxy(int $galaxyId): array
    {
        /** @var list<FleetMovement> */
        return $this->createQueryBuilder('m')
            ->addSelect('f', 'o')
            ->join('m.fleet', 'f')
            ->join('m.order', 'o')
            ->andWhere('m.origin.galaxyId = :galaxy')
            ->setParameter('galaxy', $galaxyId)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
