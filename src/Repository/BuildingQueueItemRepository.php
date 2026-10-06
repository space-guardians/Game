<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BuildingQueueItem;
use App\Entity\Planet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BuildingQueueItem>
 */
final class BuildingQueueItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuildingQueueItem::class);
    }

    public function findActiveFor(Planet $planet): ?BuildingQueueItem
    {
        return $this->findOneBy(['planet' => $planet]);
    }
}
