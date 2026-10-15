<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NpcBehaviorProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NpcBehaviorProfile>
 */
final class NpcBehaviorProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NpcBehaviorProfile::class);
    }

    public function findOneByCode(string $code): ?NpcBehaviorProfile
    {
        return $this->findOneBy(['code' => $code]);
    }
}
