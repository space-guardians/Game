<?php

declare(strict_types=1);

namespace App\Entity;

use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Niveau d'un bâtiment sur une planète ; sans ligne, le niveau est 0. Pas de niveau maximal (§2.2, §4.3).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'planet_building_unique', fields: ['planet', 'type'])]
#[Auditable]
final class PlanetBuilding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'buildings')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Planet $planet,
        /** Un type de bâtiment construit quelque part ne se supprime pas (clé étrangère restrictive) */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private readonly BuildingType $type,
        #[ORM\Column]
        private int $level = 0,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function getType(): BuildingType
    {
        return $this->type;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        if ($level < 0) {
            throw new \InvalidArgumentException('Un niveau de bâtiment ne peut pas être négatif.');
        }
        $this->level = $level;
    }

    public function __toString(): string
    {
        return \sprintf('%s niv. %d %s', $this->type->getName(), $this->level, $this->planet);
    }
}
