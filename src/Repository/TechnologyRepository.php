<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Technology;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Technology>
 */
final class TechnologyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Technology::class);
    }

    /** @return list<Technology> */
    public function findAllOrdered(): array
    {
        /** @var list<Technology> */
        return $this->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByCode(string $code): ?Technology
    {
        return $this->findOneBy(['code' => $code]);
    }
}
