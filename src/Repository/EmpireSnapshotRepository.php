<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\EmpireSnapshot;
use App\Enum\Ranking\ProgressMetric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmpireSnapshot>
 */
final class EmpireSnapshotRepository extends ServiceEntityRepository
{
    /** Empires de tête dont la moyenne sert de comparaison */
    public const int TOP = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmpireSnapshot::class);
    }

    /**
     * Instantanés d'un empire depuis une date, du plus ancien au plus récent.
     *
     * @return list<EmpireSnapshot>
     */
    public function findSince(Empire $empire, \DateTimeImmutable $since): array
    {
        /** @var list<EmpireSnapshot> */
        return $this->createQueryBuilder('s')
            ->where('s.empire = :empire')
            ->andWhere('s.takenAt >= :since')
            ->setParameter('empire', $empire)
            ->setParameter('since', $since)
            ->orderBy('s.takenAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Moyenne de tous les empires et moyenne du top 10 du classement global, pour chaque prise depuis une date.
     *
     * @return array<string, array<string, array{average: float, top: float}>> par instant (Y-m-d H:i:s), puis par
     *                                                                         grandeur (ProgressMetric)
     */
    public function benchmarksSince(\DateTimeImmutable $since): array
    {
        $columns = [];
        foreach (ProgressMetric::cases() as $metric) {
            $columns[] = \sprintf('AVG(%1$s) AS %1$s_average, AVG(%1$s) FILTER (WHERE position <= %2$d) AS %1$s_top', $metric->value, self::TOP);
        }
        // Rang de chaque empire au classement global au moment de la prise
        $sql = \sprintf(
            'SELECT taken_at, %s FROM (SELECT s.*, ROW_NUMBER() OVER (PARTITION BY taken_at ORDER BY total DESC, empire_id ASC) AS position FROM empire_snapshot s WHERE taken_at >= :since) ranked GROUP BY taken_at ORDER BY taken_at',
            implode(', ', $columns),
        );
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, ['since' => $since->format('Y-m-d H:i:s')]);

        $benchmarks = [];
        foreach ($rows as $row) {
            $at = (string) $row['taken_at'];
            foreach (ProgressMetric::cases() as $metric) {
                $benchmarks[$at][$metric->value] = [
                    'average' => (float) $row[$metric->value . '_average'],
                    'top' => (float) $row[$metric->value . '_top'],
                ];
            }
        }

        return $benchmarks;
    }

    /** Efface les instantanés plus anciens qu'une date ; renvoie le nombre supprimé */
    public function purgeBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.takenAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
