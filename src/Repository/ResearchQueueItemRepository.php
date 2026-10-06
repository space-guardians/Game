<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\ResearchQueueItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResearchQueueItem>
 */
final class ResearchQueueItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchQueueItem::class);
    }

    public function findActiveFor(Empire $empire): ?ResearchQueueItem
    {
        return $this->findOneBy(['empire' => $empire]);
    }
}
