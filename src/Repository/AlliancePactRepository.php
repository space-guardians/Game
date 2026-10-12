<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlliancePact;
use App\Entity\Empire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlliancePact>
 */
final class AlliancePactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlliancePact::class);
    }

    /** Un pacte en vigueur lie-t-il ces deux empires à cet instant ? */
    public function activeBetween(Empire $a, Empire $b, \DateTimeImmutable $at): bool
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('(p.proposer = :a AND p.partner = :b) OR (p.proposer = :b AND p.partner = :a)')
            ->andWhere('p.concludedAt IS NOT NULL AND p.concludedAt <= :at AND p.endsAt > :at')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setParameter('at', $at)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Pacte en vigueur ou proposition en attente entre ces deux empires */
    public function findOpenBetween(Empire $a, Empire $b, \DateTimeImmutable $at): ?AlliancePact
    {
        /** @var AlliancePact|null */
        return $this->createQueryBuilder('p')
            ->where('(p.proposer = :a AND p.partner = :b) OR (p.proposer = :b AND p.partner = :a)')
            ->andWhere('p.concludedAt IS NULL OR p.endsAt > :at')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setParameter('at', $at)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Pactes d'un empire encore ouverts (en vigueur, ou propositions en attente), les plus récents d'abord.
     *
     * @return list<AlliancePact>
     */
    public function findOpenFor(Empire $empire, \DateTimeImmutable $at): array
    {
        /** @var list<AlliancePact> */
        return $this->createQueryBuilder('p')
            ->where('p.proposer = :empire OR p.partner = :empire')
            ->andWhere('p.concludedAt IS NULL OR p.endsAt > :at')
            ->setParameter('empire', $empire)
            ->setParameter('at', $at)
            ->orderBy('p.proposedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Partenaires de pactes en vigueur d'un empire.
     *
     * @return list<Empire>
     */
    public function findPartnersOf(Empire $empire, \DateTimeImmutable $at): array
    {
        $partners = [];
        foreach ($this->findOpenFor($empire, $at) as $pact) {
            if ($pact->isActiveAt($at)) {
                $partners[] = $pact->counterpart($empire);
            }
        }

        return $partners;
    }
}
