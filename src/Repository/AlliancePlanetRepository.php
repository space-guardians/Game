<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Alliance;
use App\Entity\AlliancePlanet;
use App\Entity\Planet;
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

    public function findOneByPlanet(Planet $planet): ?AlliancePlanet
    {
        return $this->findOneBy(['planet' => $planet]);
    }
}
