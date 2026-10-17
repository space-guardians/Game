<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\EscortAssignment;
use App\Entity\Fleet;
use App\Entity\PlayerQuest;
use App\Enum\Market\PlayerQuestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerQuest>
 */
final class PlayerQuestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerQuest::class);
    }

    /**
     * Contrats ouverts des autres empires, du prochain départ au plus lointain.
     *
     * @return list<PlayerQuest>
     */
    public function findOpenForOthers(Empire $empire): array
    {
        /** @var list<PlayerQuest> */
        return $this->createQueryBuilder('q')
            ->where('q.status = :open')
            ->andWhere('q.poster != :empire')
            ->setParameter('open', PlayerQuestStatus::Open)
            ->setParameter('empire', $empire)
            ->orderBy('q.departsAt', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Contrats postés par l'empire ou qu'il escorte, en cours d'abord puis les plus récents.
     *
     * @return list<PlayerQuest>
     */
    public function findInvolving(Empire $empire, int $limit = 30): array
    {
        /** @var list<PlayerQuest> */
        return $this->createQueryBuilder('q')
            ->where('q.poster = :empire')
            ->orWhere(\sprintf('EXISTS (SELECT e.id FROM %s e WHERE e.quest = q AND e.empire = :empire)', EscortAssignment::class))
            ->setParameter('empire', $empire)
            ->orderBy('q.createdAt', 'DESC')
            ->addOrderBy('q.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Contrat ouvert ou en route dont cette flotte est le convoi */
    public function findActiveForConvoy(Fleet $fleet): ?PlayerQuest
    {
        /** @var PlayerQuest|null */
        return $this->createQueryBuilder('q')
            ->where('q.convoy = :fleet')
            ->andWhere('q.status IN (:active)')
            ->setParameter('fleet', $fleet)
            ->setParameter('active', [PlayerQuestStatus::Open, PlayerQuestStatus::Underway])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Identifiant du contrat en route dont cette flotte est le convoi, sans charger le contrat : chargé après un combat
     * qui a pu anéantir ses flottes, il n'y renvoie plus (clés étrangères remises à nul par la base).
     */
    public function underwayIdForConvoy(Fleet $fleet): ?int
    {
        $id = $this->createQueryBuilder('q')
            ->select('q.id')
            ->where('q.convoy = :fleet')
            ->andWhere('q.status = :underway')
            ->setParameter('fleet', $fleet)
            ->setParameter('underway', PlayerQuestStatus::Underway)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return null === $id ? null : (int) $id;
    }

    /** Contrat ouvert ou en route auquel cette flotte est attachée comme escorte */
    public function findActiveForEscort(Fleet $fleet): ?PlayerQuest
    {
        /** @var PlayerQuest|null */
        return $this->createQueryBuilder('q')
            ->join('q.escorts', 'e')
            ->where('e.fleet = :fleet')
            ->andWhere('q.status IN (:active)')
            ->setParameter('fleet', $fleet)
            ->setParameter('active', [PlayerQuestStatus::Open, PlayerQuestStatus::Underway])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Flottes dont la destination est secrète (§4.15) : convois et escortes des contrats en route.
     *
     * @return array<int, true>
     */
    public function secretFleetIds(): array
    {
        $ids = [];
        $rows = $this->createQueryBuilder('q')
            ->select('IDENTITY(q.convoy) AS convoy', 'IDENTITY(e.fleet) AS escort')
            ->leftJoin('q.escorts', 'e')
            ->where('q.status = :underway')
            ->setParameter('underway', PlayerQuestStatus::Underway)
            ->getQuery()
            ->getScalarResult();
        foreach ($rows as $row) {
            foreach ([$row['convoy'], $row['escort']] as $id) {
                if (null !== $id) {
                    $ids[(int) $id] = true;
                }
            }
        }

        return $ids;
    }
}
