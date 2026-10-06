<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShipType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShipType>
 */
final class ShipTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShipType::class);
    }

    /** @return list<ShipType> */
    public function findAllOrdered(): array
    {
        /** @var list<ShipType> */
        return $this->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByCode(string $code): ?ShipType
    {
        return $this->findOneBy(['code' => $code]);
    }
}
