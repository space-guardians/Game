<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\Planet;
use App\Entity\PlanetListing;
use App\Enum\Market\PlanetListingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanetListing>
 */
final class PlanetListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanetListing::class);
    }

    /** Annonce en cours sur cette planète, mise en vente ou proposée en échange */
    public function findOpenInvolving(Planet $planet): ?PlanetListing
    {
        /** @var PlanetListing|null */
        return $this->createQueryBuilder('l')
            ->where('l.planet = :planet OR l.offeredPlanet = :planet')
            ->andWhere('l.status IN (:open)')
            ->setParameter('planet', $planet)
            ->setParameter('open', [PlanetListingStatus::Open, PlanetListingStatus::Offered])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Planètes sur le marché où l'empire est vendeur, acheteur ou auteur d'une proposition, dans ces états.
     *
     * @param list<PlanetListingStatus> $statuses
     *
     * @return list<PlanetListing>
     */
    public function findInvolving(Empire $empire, array $statuses, int $limit = 50): array
    {
        /** @var list<PlanetListing> */
        return $this->createQueryBuilder('l')
            ->addSelect('p')
            ->join('l.planet', 'p')
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

    /**
     * Annonces ouvertes à tous, les plus récentes d'abord.
     *
     * @return list<PlanetListing>
     */
    public function findOpen(int $limit = 50): array
    {
        /** @var list<PlanetListing> */
        return $this->createQueryBuilder('l')
            ->addSelect('p', 'e')
            ->join('l.planet', 'p')
            ->join('l.seller', 'e')
            ->where('l.status = :open')
            ->setParameter('open', PlanetListingStatus::Open)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
