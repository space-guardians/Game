<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Empire>
 */
final class EmpireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Empire::class);
    }

    public function findOneByUser(User $user): ?Empire
    {
        return $this->findOneBy(['user' => $user]);
    }

    /** Empire désigné par son nom, sans tenir compte de la casse ni des espaces superflus */
    public function findOneByName(string $name): ?Empire
    {
        /** @var Empire|null */
        return $this->createQueryBuilder('e')
            ->where('LOWER(e.name) = LOWER(:name)')
            ->setParameter('name', Empire::normalizeName($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Sans tenir compte de la casse ni des espaces superflus : « Orion » et « orion » sont le même nom */
    public function nameExists(string $name): bool
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('LOWER(e.name) = LOWER(:name)')
            ->setParameter('name', Empire::normalizeName($name))
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
