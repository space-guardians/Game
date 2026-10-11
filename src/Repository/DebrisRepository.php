<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Debris;
use App\Entity\SpaceLocation;
use App\Service\Combat\Battles;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Debris>
 */
final class DebrisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Debris::class);
    }

    /** Champ de débris à cet endroit (même lieu qu'une bataille), s'il y en a un */
    public function findAt(SpaceLocation $location): ?Debris
    {
        foreach ($this->findBy(['location.galaxyId' => $location->galaxyId], ['id' => 'ASC']) as $debris) {
            if (Battles::samePlace($debris->getLocation(), $location)) {
                return $debris;
            }
        }

        return null;
    }
}
