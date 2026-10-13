<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\PlayerIgnore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerIgnore>
 */
final class PlayerIgnoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerIgnore::class);
    }

    /** L'empire ignore-t-il ce joueur ? */
    public function ignores(Empire $empire, Empire $other): bool
    {
        return null !== $this->findOneBy(['empire' => $empire, 'ignored' => $other]);
    }

    /**
     * Joueurs ignorés par un empire, par nom.
     *
     * @return list<PlayerIgnore>
     */
    public function findOf(Empire $empire): array
    {
        /** @var list<PlayerIgnore> */
        return $this->createQueryBuilder('i')
            ->join('i.ignored', 'e')
            ->addSelect('e')
            ->where('i.empire = :empire')
            ->setParameter('empire', $empire)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<int> identifiants des empires ignorés par cet empire */
    public function ignoredIdsOf(Empire $empire): array
    {
        return array_map('intval', $this->createQueryBuilder('i')
            ->select('IDENTITY(i.ignored)')
            ->where('i.empire = :empire')
            ->setParameter('empire', $empire)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * Parmi ces empires, ceux qui ignorent l'auteur.
     *
     * @param list<int> $empireIds
     *
     * @return list<int>
     */
    public function ignoringAmong(Empire $author, array $empireIds): array
    {
        if ([] === $empireIds) {
            return [];
        }

        return array_map('intval', $this->createQueryBuilder('i')
            ->select('IDENTITY(i.empire)')
            ->where('i.ignored = :author')
            ->andWhere('i.empire IN (:ids)')
            ->setParameter('author', $author)
            ->setParameter('ids', $empireIds)
            ->getQuery()
            ->getSingleColumnResult());
    }
}
