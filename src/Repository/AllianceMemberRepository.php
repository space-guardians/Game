<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AllianceMember;
use App\Entity\Empire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AllianceMember>
 */
final class AllianceMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AllianceMember::class);
    }

    public function findOneByEmpire(Empire $empire): ?AllianceMember
    {
        return $this->findOneBy(['empire' => $empire]);
    }
}
