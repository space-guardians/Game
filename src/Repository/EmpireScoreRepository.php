<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Alliance;
use App\Entity\AllianceMember;
use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Enum\Ranking\RankingCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmpireScore>
 */
final class EmpireScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmpireScore::class);
    }

    /**
     * Une page du classement des empires, du meilleur au moins bon (ex æquo départagés par ancienneté).
     *
     * @return list<array{score: EmpireScore, points: int, alliance: ?string}>
     */
    public function findPage(RankingCategory $category, int $offset, int $limit): array
    {
        /** @var list<array{0: EmpireScore, points: int, alliance: ?string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->addSelect('e', \sprintf('s.%s AS points', $category->field()), 'a.tag AS alliance')
            ->join('s.empire', 'e')
            ->leftJoin(AllianceMember::class, 'm', 'WITH', 'm.empire = e')
            ->leftJoin('m.alliance', 'a')
            ->orderBy('points', 'DESC')
            ->addOrderBy('e.foundedAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn(array $row): array => ['score' => $row[0], 'points' => (int) $row['points'], 'alliance' => $row['alliance']], $rows);
    }

    /** Rang de l'empire dans la catégorie (1 = premier), null s'il n'est pas encore classé */
    public function rankOf(Empire $empire, RankingCategory $category): ?int
    {
        $score = $this->findOneBy(['empire' => $empire]);
        if (null === $score) {
            return null;
        }
        $field = $category->field();
        $points = $score->points($category);

        return 1 + (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.empire', 'e')
            ->andWhere(\sprintf('s.%1$s > :points OR (s.%1$s = :points AND (e.foundedAt < :founded OR (e.foundedAt = :founded AND e.id < :id)))', $field))
            ->setParameter('points', $points)
            ->setParameter('founded', $empire->getFoundedAt())
            ->setParameter('id', $empire->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Classement des alliances : points cumulés de leurs membres, du meilleur au moins bon.
     *
     * @return list<array{alliance: Alliance, points: int, members: int}>
     */
    public function findAlliances(RankingCategory $category, int $offset, int $limit): array
    {
        /** @var list<array{0: Alliance, points: int|string, members: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('a', \sprintf('SUM(s.%s) AS points', $category->field()), 'COUNT(m.id) AS members')
            ->from(Alliance::class, 'a')
            ->join(AllianceMember::class, 'm', 'WITH', 'm.alliance = a')
            ->join(EmpireScore::class, 's', 'WITH', 's.empire = m.empire')
            ->groupBy('a.id')
            ->orderBy('points', 'DESC')
            ->addOrderBy('a.foundedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn(array $row): array => ['alliance' => $row[0], 'points' => (int) $row['points'], 'members' => (int) $row['members']], $rows);
    }

    public function countAlliances(): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT m.alliance)')
            ->from(AllianceMember::class, 'm')
            ->join(EmpireScore::class, 's', 'WITH', 's.empire = m.empire')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
