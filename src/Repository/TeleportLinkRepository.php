<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Alliance;
use App\Entity\AlliancePlanet;
use App\Entity\TeleportLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeleportLink>
 */
final class TeleportLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeleportLink::class);
    }

    /** Liaison d'une porte, de quelque côté qu'elle soit */
    public function findFor(AlliancePlanet $gate): ?TeleportLink
    {
        /** @var TeleportLink|null */
        return $this->createQueryBuilder('l')
            ->andWhere('l.gateA = :gate OR l.gateB = :gate')
            ->setParameter('gate', $gate)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<TeleportLink> */
    public function findOf(Alliance $alliance): array
    {
        return $this->findBy(['alliance' => $alliance], ['id' => 'ASC']);
    }
}
