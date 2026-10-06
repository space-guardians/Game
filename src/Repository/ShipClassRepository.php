<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShipClass;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShipClass>
 */
final class ShipClassRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShipClass::class);
    }

    public function findOneByCode(string $code): ?ShipClass
    {
        return $this->findOneBy(['code' => $code]);
    }
}
