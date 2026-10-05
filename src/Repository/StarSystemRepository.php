<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\StarSystem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StarSystem>
 */
final class StarSystemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StarSystem::class);
    }

    /**
     * Positions des systèmes d'une galaxie, sans charger les entités (aperçu de la carte).
     *
     * @return list<GlobalPosition>
     */
    public function positions(Galaxy $galaxy): array
    {
        /** @var list<array{x: float, y: float}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.position.x AS x', 's.position.y AS y')
            ->where('s.galaxy = :galaxy')
            ->setParameter('galaxy', $galaxy)
            ->orderBy('s.number')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $row): GlobalPosition => new GlobalPosition((float) $row['x'], (float) $row['y']), $rows);
    }
}
