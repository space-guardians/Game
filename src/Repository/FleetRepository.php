<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\Fleet;
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
}
