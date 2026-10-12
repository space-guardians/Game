<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Alliance\AllianceBuildingType;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bâtiment unique d'une planète d'alliance (§4.10) : scanner d'alliance ou porte de téléportation, avec son niveau.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'alliance_building_unique', fields: ['alliancePlanet', 'type'])]
#[Auditable]
final class AllianceBuilding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $level = 0;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'buildings')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly AlliancePlanet $alliancePlanet,
        #[ORM\Column(length: 20, enumType: AllianceBuildingType::class)]
        private readonly AllianceBuildingType $type,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlliancePlanet(): AlliancePlanet
    {
        return $this->alliancePlanet;
    }

    public function getType(): AllianceBuildingType
    {
        return $this->type;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function levelUp(): void
    {
        ++$this->level;
    }
}
