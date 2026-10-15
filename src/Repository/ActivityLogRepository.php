<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Enum\FairPlay\ActivityAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
final class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    public function countSince(string $ipHash, ActivityAction $action, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.ipHash = :ip')
            ->andWhere('a.action = :action')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('ip', $ipHash)
            ->setParameter('action', $action)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<ActivityLog> journal d'un joueur, le plus récent d'abord */
    public function findLatestFor(User $user, int $limit): array
    {
        /** @var list<ActivityLog> */
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Autres comptes vus depuis les mêmes adresses que ce joueur (comptes multiples), par adresse e-mail.
     *
     * @return list<User>
     */
    public function findUsersSharingAddressWith(User $user): array
    {
        $hashes = $this->createQueryBuilder('a')
            ->select('DISTINCT a.ipHash')
            ->where('a.user = :user')
            ->andWhere('a.ipHash IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();
        if ([] === $hashes) {
            return [];
        }

        /** @var list<User> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u IN (SELECT IDENTITY(a.user) FROM ' . ActivityLog::class . ' a WHERE a.ipHash IN (:hashes))')
            ->andWhere('u != :user')
            ->setParameter('hashes', $hashes)
            ->setParameter('user', $user)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
