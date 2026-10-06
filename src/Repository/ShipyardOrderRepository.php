<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planet;
use App\Entity\ShipyardOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShipyardOrder>
 */
final class ShipyardOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShipyardOrder::class);
    }

    /**
     * Commandes en cours et en attente d'une planète, dans l'ordre de livraison.
     *
     * @return list<ShipyardOrder>
     */
    public function findForPlanet(Planet $planet): array
    {
        /** @var list<ShipyardOrder> */
        return $this->findBy(['planet' => $planet], ['endsAt' => 'ASC', 'id' => 'ASC']);
    }
}
