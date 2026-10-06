<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BuildingType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BuildingType>
 */
final class BuildingTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuildingType::class);
    }

    /** @return list<BuildingType> */
    public function findAllOrdered(): array
    {
        /** @var list<BuildingType> */
        return $this->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByCode(string $code): ?BuildingType
    {
        return $this->findOneBy(['code' => $code]);
    }
}
