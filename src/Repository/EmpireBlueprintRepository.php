<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\EmpireBlueprint;
use App\Entity\ShipType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmpireBlueprint>
 */
final class EmpireBlueprintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmpireBlueprint::class);
    }

    public function has(Empire $empire, ShipType $type): bool
    {
        return $this->count(['empire' => $empire, 'shipType' => $type]) > 0;
    }

    /** @return list<EmpireBlueprint> plans de l'empire, dans l'ordre d'obtention */
    public function findOwnedBy(Empire $empire): array
    {
        return $this->findBy(['empire' => $empire], ['acquiredAt' => 'ASC', 'id' => 'ASC']);
    }

    /** @return array<string, true> codes des types dont l'empire détient le plan */
    public function codesOwnedBy(Empire $empire): array
    {
        $codes = [];
        foreach ($this->findOwnedBy($empire) as $blueprint) {
            $codes[$blueprint->getShipType()->getCode()] = true;
        }

        return $codes;
    }
}
