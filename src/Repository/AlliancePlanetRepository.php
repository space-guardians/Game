<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Alliance;
use App\Entity\AlliancePlanet;
use App\Entity\Planet;
use App\Enum\Alliance\AllianceBuildingType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlliancePlanet>
 */
final class AlliancePlanetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlliancePlanet::class);
    }

    /** @return list<AlliancePlanet> */
    public function findOf(Alliance $alliance): array
    {
        return $this->findBy(['alliance' => $alliance], ['id' => 'ASC']);
    }

    /**
     * Planètes d'alliance d'une galaxie dotées d'un scanner.
     *
     * @return list<AlliancePlanet>
     */
    public function findScanningInGalaxy(int $galaxyId): array
    {
        /** @var list<AlliancePlanet> */
        return $this->createQueryBuilder('ap')
            ->addSelect('p', 'a')
            ->join('ap.planet', 'p')
            ->join('ap.alliance', 'a')
            ->join('p.system', 's')
            ->join('ap.buildings', 'b')
            ->andWhere('IDENTITY(s.galaxy) = :galaxy')
            ->andWhere('b.type = :scanner')
            ->andWhere('b.level > 0')
            ->setParameter('galaxy', $galaxyId)
            ->setParameter('scanner', AllianceBuildingType::Scanner)
            ->orderBy('ap.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByPlanet(Planet $planet): ?AlliancePlanet
    {
        return $this->findOneBy(['planet' => $planet]);
    }
}
