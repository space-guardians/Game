<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
final class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnread(Empire $empire): int
    {
        return $this->count(['empire' => $empire, 'readAt' => null]);
    }

    /**
     * Historique d'un empire, les plus récentes d'abord.
     *
     * @return list<Notification>
     */
    public function findPage(Empire $empire, int $offset, int $limit): array
    {
        /** @var list<Notification> */
        return $this->createQueryBuilder('n')
            ->where('n.empire = :empire')
            ->setParameter('empire', $empire)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Marque toutes les notifications de l'empire comme lues ; renvoie leur nombre */
    public function markAllRead(Empire $empire, \DateTimeImmutable $at): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':at')
            ->where('n.empire = :empire')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('empire', $empire)
            ->getQuery()
            ->execute();
    }
}
