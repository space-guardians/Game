<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Galaxy;
use App\Entity\Merchant;
use App\Entity\SpaceLocation;
use App\Entity\StarSystem;
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

    /** Marchand dont la station est à cette position (un au plus par système) */
    public function findAt(SpaceLocation $location): ?Merchant
    {
        if (null === $location->systemId || null === $location->localX || null === $location->localY || null !== $location->planetId) {
            return null;
        }
        $merchant = $this->findOneBy(['system' => $location->systemId]);

        return null !== $merchant && abs($merchant->getLocalX() - $location->localX) < 0.01 && abs($merchant->getLocalY() - $location->localY) < 0.01 ? $merchant : null;
    }

    /** Marchand d'un système, s'il y en a un */
    public function findInSystem(StarSystem $system): ?Merchant
    {
        return $this->findOneBy(['system' => $system]);
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
