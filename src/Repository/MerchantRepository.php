<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Galaxy;
use App\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Merchant>
 */
final class MerchantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Merchant::class);
    }

    /**
     * Marchands d'une galaxie, par numéro de système.
     *
     * @return list<Merchant>
     */
    public function findInGalaxy(Galaxy $galaxy): array
    {
        /** @var list<Merchant> */
        return $this->createQueryBuilder('m')
            ->addSelect('s')
            ->join('m.system', 's')
            ->where('s.galaxy = :galaxy')
            ->setParameter('galaxy', $galaxy)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countInGalaxy(Galaxy $galaxy): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.system', 's')
            ->where('s.galaxy = :galaxy')
            ->setParameter('galaxy', $galaxy)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
