<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassMatchup;
use App\Model\Combat\MatchupMatrix;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassMatchup>
 */
final class ClassMatchupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassMatchup::class);
    }

    /** Matrice complète, par codes de classe, pour le combat */
    public function matrix(): MatchupMatrix
    {
        $multipliers = [];
        foreach ($this->findAllWithClasses() as $matchup) {
            $multipliers[$matchup->getAttacker()->getCode()][$matchup->getDefender()->getCode()] = $matchup->getMultiplier();
        }

        return new MatchupMatrix($multipliers);
    }

    /** @return list<ClassMatchup> */
    public function findAllWithClasses(): array
    {
        /** @var list<ClassMatchup> */
        return $this->createQueryBuilder('m')
            ->addSelect('a', 'd')
            ->join('m.attacker', 'a')
            ->join('m.defender', 'd')
            ->getQuery()
            ->getResult();
    }
}
