<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Galaxy;
use App\Entity\Planet;
use App\Model\Universe\PlanetCandidate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Planet>
 */
final class PlanetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Planet::class);
    }

    public function countOwnedIn(Galaxy $galaxy): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->join('p.system', 's')
            ->where('s.galaxy = :galaxy')
            ->andWhere('p.owner IS NOT NULL')
            ->setParameter('galaxy', $galaxy)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Planètes libres de la première galaxie (par numéro) qui en compte encore : les galaxies se remplissent l'une
     * après l'autre.
     *
     * @return list<PlanetCandidate>
     */
    public function startingCandidates(): array
    {
        $galaxy = $this->createQueryBuilder('p')
            ->select('MIN(g.number)')
            ->join('p.system', 's')
            ->join('s.galaxy', 'g')
            ->where('p.owner IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
        if (null === $galaxy) {
            return [];
        }

        /** @var list<array{systemId: int, occupied: int}> $occupancy */
        $occupancy = $this->createQueryBuilder('p')
            ->select('s.id AS systemId', 'COUNT(p.id) AS occupied')
            ->join('p.system', 's')
            ->join('s.galaxy', 'g')
            ->where('p.owner IS NOT NULL')
            ->andWhere('g.number = :galaxy')
            ->setParameter('galaxy', $galaxy)
            ->groupBy('s.id')
            ->getQuery()
            ->getArrayResult();
        $occupied = array_column($occupancy, 'occupied', 'systemId');

        /** @var list<array{planetId: int, systemId: int, x: float, y: float}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.id AS planetId', 's.id AS systemId', 's.position.x AS x', 's.position.y AS y')
            ->join('p.system', 's')
            ->join('s.galaxy', 'g')
            ->where('p.owner IS NULL')
            ->andWhere('g.number = :galaxy')
            ->setParameter('galaxy', $galaxy)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $row): PlanetCandidate => new PlanetCandidate(
            $row['planetId'],
            $row['systemId'],
            hypot((float) $row['x'], (float) $row['y']),
            (int) ($occupied[$row['systemId']] ?? 0),
        ), $rows);
    }
}
