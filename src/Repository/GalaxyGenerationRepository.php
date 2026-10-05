<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GalaxyGeneration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalaxyGeneration>
 */
final class GalaxyGenerationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalaxyGeneration::class);
    }
}
