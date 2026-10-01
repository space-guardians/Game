<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Galaxy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Galaxy>
 */
final class GalaxyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Galaxy::class);
    }

    /** Numéro à donner à la prochaine galaxie ajoutée à l'univers */
    public function nextNumber(): int
    {
        $highest = $this->createQueryBuilder('g')
            ->select('MAX(g.number)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $highest + 1;
    }

    public function numberExists(int $number): bool
    {
        return $this->count(['number' => $number]) > 0;
    }
}
