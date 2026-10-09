<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\ExplorationEventInstance;
use App\Enum\Exploration\ExplorationEventStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExplorationEventInstance>
 */
final class ExplorationEventInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExplorationEventInstance::class);
    }

    /**
     * Journal d'exploration d'un empire : décisions en attente d'abord, puis les plus récents.
     *
     * @return list<ExplorationEventInstance>
     */
    public function findForEmpire(Empire $empire, int $limit = 30): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('CASE WHEN e.status = :awaiting THEN 0 ELSE 1 END AS HIDDEN pending_first')
            ->andWhere('e.empire = :empire')
            ->setParameter('empire', $empire)
            ->setParameter('awaiting', ExplorationEventStatus::AwaitingChoice)
            ->addOrderBy('pending_first', 'ASC')
            ->addOrderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * État en base, ligne verrouillée jusqu'à la fin de la transaction en cours : un choix et une échéance simultanés
     * ne règlent l'événement qu'une fois (l'entité en mémoire a pu être chargée avant).
     */
    public function lockedStatus(int $id): ?ExplorationEventStatus
    {
        $status = $this->getEntityManager()->getConnection()->fetchOne('SELECT status FROM exploration_event_instance WHERE id = ? FOR UPDATE', [$id]);

        return \is_string($status) ? ExplorationEventStatus::from($status) : null;
    }

    public function countAwaitingChoice(Empire $empire): int
    {
        return $this->count(['empire' => $empire, 'status' => ExplorationEventStatus::AwaitingChoice]);
    }

    /**
     * Décisions attendues par les flottes d'un empire : tant qu'elle n'est pas prise, la flotte reste sur place.
     *
     * @return array<int, ExplorationEventInstance> par identifiant de flotte
     */
    public function findAwaitingByFleet(Empire $empire): array
    {
        $awaiting = [];
        foreach ($this->findBy(['empire' => $empire, 'status' => ExplorationEventStatus::AwaitingChoice], ['id' => 'ASC']) as $event) {
            $fleet = $event->getFleet();
            if (null !== $fleet) {
                $awaiting[(int) $fleet->getId()] = $event;
            }
        }

        return $awaiting;
    }
}
