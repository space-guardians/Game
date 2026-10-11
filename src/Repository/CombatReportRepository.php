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
     * Rapports reçus par un empire (engagé dans la bataille), les plus récents d'abord.
     *
     * @return list<CombatReport>
     */
    public function findForEmpire(Empire $empire, int $limit = 50): array
    {
        /** @var list<CombatReport> */
        return $this->createQueryBuilder('r')
            ->join('r.battle', 'b')
            ->andWhere(\sprintf('EXISTS (SELECT p.id FROM %s p WHERE p.battle = b AND p.empire = :empire)', BattleParticipant::class))
            ->setParameter('empire', $empire)
            ->orderBy('r.foughtAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Rapport reçu par l'empire, ou null s'il n'y était pas engagé */
    public function findOneForEmpire(int $id, Empire $empire): ?CombatReport
    {
        $report = $this->find($id);

        return null !== $report && null !== $report->sideOf($empire) ? $report : null;
    }
}
