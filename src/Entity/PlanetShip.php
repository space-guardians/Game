<?php

declare(strict_types=1);

namespace App\Entity;

use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vaisseaux d'un type stationnés sur une planète (inventaire) ; sans ligne, il n'y en a aucun.
 *
 * @see §4.5 du cahier des charges
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'planet_ship_unique', fields: ['planet', 'type'])]
#[Auditable]
final class PlanetShip implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'ships')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Planet $planet,
        /** Un type de vaisseau possédé quelque part ne se supprime pas (clé étrangère restrictive) */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private readonly ShipType $type,
        #[ORM\Column]
        private int $quantity = 0,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
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
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Un nombre de vaisseaux ne peut pas être négatif.');
        }
        $this->quantity = $quantity;
    }

    public function __toString(): string
    {
        return \sprintf('%d × %s %s', $this->quantity, $this->type->getName(), $this->planet);
    }
}
