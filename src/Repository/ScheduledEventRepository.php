<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Enum\Scheduling\ScheduledEventStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledEvent>
 */
final class ScheduledEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledEvent::class);
    }

    /** Statut lu en base, sans passer par l'entité éventuellement déjà chargée */
    public function currentStatus(int $id): ?ScheduledEventStatus
    {
        $status = $this->getEntityManager()->getConnection()->fetchOne('SELECT status FROM scheduled_event WHERE id = ?', [$id]);

        return \is_string($status) ? ScheduledEventStatus::from($status) : null;
    }

    /**
     * Identifiants des événements en attente arrivés à échéance, les plus anciens d'abord.
     *
     * @return list<int>
     */
    public function findDueIds(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<int> */
        return $this->createQueryBuilder('e')
            ->select('e.id')
            ->where('e.status = :pending')
            ->andWhere('e.dueAt <= :now')
            ->setParameter('pending', ScheduledEventStatus::Pending)
            ->setParameter('now', $now)
            ->orderBy('e.dueAt')
            ->addOrderBy('e.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Événements échus d'une planète, dans l'ordre où ils doivent être résolus.
     *
     * @return list<ScheduledEvent>
     */
    public function findDueForPlanet(Planet $planet, \DateTimeImmutable $now): array
    {
        /** @var list<ScheduledEvent> */
        return $this->createQueryBuilder('e')
            ->where('e.planet = :planet')
            ->andWhere('e.status = :pending')
            ->andWhere('e.dueAt <= :now')
            ->setParameter('planet', $planet)
            ->setParameter('pending', ScheduledEventStatus::Pending)
            ->setParameter('now', $now)
            ->orderBy('e.dueAt')
            ->addOrderBy('e.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements en attente, par type.
     *
     * @return array<string, int>
     */
    public function countPendingByType(): array
    {
        /** @var list<array{type: string, total: int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.type, COUNT(e.id) AS total')
            ->where('e.status = :pending')
            ->setParameter('pending', ScheduledEventStatus::Pending)
            ->groupBy('e.type')
            ->orderBy('e.type')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'total', 'type');
    }

    /** Événements en attente dont l'échéance est passée depuis avant $dueBefore : ni le réveil ni la vérification périodique ne les ont résolus */
    public function countLate(\DateTimeImmutable $dueBefore): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.status = :pending')
            ->andWhere('e.dueAt < :dueBefore')
            ->setParameter('pending', ScheduledEventStatus::Pending)
            ->setParameter('dueBefore', $dueBefore)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countFailedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.status = :failed')
            ->andWhere('e.resolvedAt >= :since')
            ->setParameter('failed', ScheduledEventStatus::Failed)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
