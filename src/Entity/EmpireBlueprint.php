<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmpireBlueprintRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Plan d'un vaisseau réservé détenu par un empire (§4.14) : gagné en battant un « boss », il permet de construire ce
 * type au chantier spatial, sur toutes les planètes de l'empire et pour toujours.
 */
#[ORM\Entity(repositoryClass: EmpireBlueprintRepository::class)]
#[ORM\UniqueConstraint(name: 'empire_blueprint_unique', fields: ['empire', 'shipType'])]
#[Auditable]
final class EmpireBlueprint implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly ShipType $shipType,
        #[ORM\Column]
        private readonly \DateTimeImmutable $acquiredAt,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getShipType(): ShipType
    {
        return $this->shipType;
    }

    public function getAcquiredAt(): \DateTimeImmutable
    {
        return $this->acquiredAt;
    }

    public function __toString(): string
    {
        return \sprintf('Plan %s (%s)', $this->shipType->getName(), $this->empire->getName());
    }
}
