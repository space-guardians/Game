<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GalaxyShapeTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalaxyShapeTemplate>
 */
final class GalaxyShapeTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalaxyShapeTemplate::class);
    }

    public function findOneByName(string $name): ?GalaxyShapeTemplate
    {
        return $this->findOneBy(['name' => $name]);
    }
}
