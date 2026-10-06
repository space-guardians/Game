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

    public function findActiveFor(Fleet $fleet): ?FleetMovement
    {
        return $this->findOneBy(['fleet' => $fleet]);
    }
}
