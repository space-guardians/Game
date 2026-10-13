<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketListing;
use App\Enum\Market\MarketListingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketListing>
 */
final class MarketListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketListing::class);
    }

    /**
     * Annonces ouvertes à tous, les plus récentes d'abord.
     *
     * @return list<MarketListing>
     */
    public function findOpen(int $limit = 50): array
    {
        /** @var list<MarketListing> */
        return $this->createQueryBuilder('l')
            ->addSelect('m', 'e')
            ->join('l.merchant', 'm')
            ->join('l.seller', 'e')
            ->where('l.status = :open')
            ->setParameter('open', MarketListingStatus::Open)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
