<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BuildingType;
use App\Entity\Prerequisite;
use App\Entity\ShipType;
use App\Entity\Technology;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prerequisite>
 */
final class PrerequisiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prerequisite::class);
    }

    /** @return list<Prerequisite> */
    public function findFor(BuildingType|Technology|ShipType $target): array
    {
        $field = match (true) {
            $target instanceof BuildingType => 'targetBuilding',
            $target instanceof Technology => 'targetTechnology',
            $target instanceof ShipType => 'targetShip',
        };

        /** @var list<Prerequisite> */
        return $this->findBy([$field => $target], ['id' => 'ASC']);
    }

    /**
     * Tous les prérequis, chargés une fois pour un écran entier (bâtiments, arbre de recherche).
     *
     * @return list<Prerequisite>
     */
    public function findAllWithRelations(): array
    {
        /** @var list<Prerequisite> */
        return $this->createQueryBuilder('p')
            ->addSelect('tb', 'tt', 'ts', 'rb', 'rt')
            ->leftJoin('p.targetBuilding', 'tb')
            ->leftJoin('p.targetTechnology', 'tt')
            ->leftJoin('p.targetShip', 'ts')
            ->leftJoin('p.requiredBuilding', 'rb')
            ->leftJoin('p.requiredTechnology', 'rt')
            ->orderBy('p.id')
            ->getQuery()
            ->getResult();
    }
}
