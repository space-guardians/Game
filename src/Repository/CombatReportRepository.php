<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BattleParticipant;
use App\Entity\CombatReport;
use App\Entity\Empire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CombatReport>
 */
final class CombatReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CombatReport::class);
    }

    /**
     * Rapports des batailles où l'un de ces empires était engagé, les plus récents d'abord.
     *
     * @param list<Empire> $empires le lecteur, et les alliés qui lui partagent leurs rapports
     *
     * @return list<CombatReport>
     */
    public function findForEmpires(array $empires, int $limit = 50): array
    {
        /** @var list<CombatReport> */
        return $this->createQueryBuilder('r')
            ->join('r.battle', 'b')
            ->andWhere(\sprintf('EXISTS (SELECT p.id FROM %s p WHERE p.battle = b AND p.empire IN (:empires))', BattleParticipant::class))
            ->setParameter('empires', $empires)
            ->orderBy('r.foughtAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rapport lisible par ces empires, ou null si aucun n'y était engagé.
     *
     * @param list<Empire> $empires
     */
    public function findOneForEmpires(int $id, array $empires): ?CombatReport
    {
        $report = $this->find($id);

        return null !== $report && null !== $report->sideOfAny($empires) ? $report : null;
    }
}
