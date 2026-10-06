<?php

declare(strict_types=1);

namespace App\Entity;

use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vaisseaux d'un type dans une flotte.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'fleet_ship_unique', fields: ['fleet', 'type'])]
#[Auditable]
final class FleetShip implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'ships')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Fleet $fleet,
        /** Un type de vaisseau présent dans une flotte ne se supprime pas (clé étrangère restrictive) */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private readonly ShipType $type,
        #[ORM\Column]
        private int $quantity,
    ) {
        $this->setQuantity($quantity);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFleet(): Fleet
    {
        return $this->fleet;
    }

    public function getType(): ShipType
    {
        return $this->type;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Une flotte compte au moins un vaisseau de chaque type qu’elle contient.');
        }
        $this->quantity = $quantity;
    }

    public function __toString(): string
    {
        return \sprintf('%d × %s (%s)', $this->quantity, $this->type->getName(), $this->fleet->getName());
    }
}
