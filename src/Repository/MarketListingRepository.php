<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\MarketListing;
use App\Entity\Merchant;
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
     * Transactions chez ce marchand pour lesquelles l'empire doit encore livrer, les plus anciennes d'abord : comme
     * vendeur (dès la publication), ou comme acheteur engagé.
     *
     * @return list<MarketListing>
     */
    public function findAwaitingDeliveryFrom(Empire $empire, Merchant $merchant): array
    {
        /** @var list<MarketListing> */
        return $this->createQueryBuilder('l')
            ->where('l.merchant = :merchant')
            ->andWhere('(l.seller = :empire AND l.sellerDelivered = false AND l.status IN (:sellerStatuses)) OR (l.buyer = :empire AND l.buyerDelivered = false AND l.status = :accepted)')
            ->setParameter('merchant', $merchant)
            ->setParameter('empire', $empire)
            ->setParameter('sellerStatuses', [MarketListingStatus::Open, MarketListingStatus::Accepted])
            ->setParameter('accepted', MarketListingStatus::Accepted)
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Annonces de ressources où l'empire est vendeur ou acheteur, dans ces états, les plus récentes d'abord.
     *
     * @param list<MarketListingStatus> $statuses
     *
     * @return list<MarketListing>
     */
    public function findInvolving(Empire $empire, array $statuses, int $limit = 50): array
    {
        /** @var list<MarketListing> */
        return $this->createQueryBuilder('l')
            ->addSelect('m')
            ->join('l.merchant', 'm')
            ->where('l.seller = :empire OR l.buyer = :empire')
            ->andWhere('l.status IN (:statuses)')
            ->setParameter('empire', $empire)
            ->setParameter('statuses', $statuses)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countOpenBy(Empire $seller): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.seller = :seller')
            ->andWhere('l.status IN (:statuses)')
            ->setParameter('seller', $seller)
            ->setParameter('statuses', [MarketListingStatus::Open, MarketListingStatus::Accepted])
            ->getQuery()
            ->getSingleScalarResult();
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
