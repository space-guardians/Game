<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Alliance\AllianceBuildingType;
use App\Repository\AlliancePlanetRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Planète d'alliance (§4.10) : planète artificielle construite par une alliance, propriété collective — la `Planet`
 * qu'elle occupe n'a pas d'empire propriétaire mais appartient à l'alliance. Elle porte des bâtiments uniques
 * (AllianceBuilding) et ses propres défenses ; elle peut être attaquée, pillée, et détruite quand son intégrité tombe
 * à zéro. Auditée (§5.6.3).
 */
#[ORM\Entity(repositoryClass: AlliancePlanetRepository::class)]
#[Auditable]
final class AlliancePlanet implements \Stringable
{
    public const int NAME_MAX_LENGTH = 40;

    /** Intégrité d'une planète d'alliance neuve */
    public const int FULL_INTEGRITY = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $integrity = self::FULL_INTEGRITY;

    /** @var Collection<int, AllianceBuilding> */
    #[ORM\OneToMany(targetEntity: AllianceBuilding::class, mappedBy: 'alliancePlanet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $buildings;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Alliance $alliance,
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private readonly Planet $planet,
        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        private readonly string $name,
        #[ORM\Column]
        private readonly \DateTimeImmutable $startedAt,
        /** Fin de la construction : la planète n'est utilisable qu'ensuite */
        #[ORM\Column]
        private readonly \DateTimeImmutable $completesAt,
    ) {
        $this->buildings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlliance(): Alliance
    {
        return $this->alliance;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletesAt(): \DateTimeImmutable
    {
        return $this->completesAt;
    }

    public function isBuiltAt(\DateTimeImmutable $at): bool
    {
        return $at >= $this->completesAt;
    }

    public function getIntegrity(): int
    {
        return $this->integrity;
    }

    /** Dégâts subis d'une victoire ennemie ; renvoie l'intégrité restante */
    public function damage(int $points): int
    {
        $this->integrity = max(0, $this->integrity - max(0, $points));

        return $this->integrity;
    }

    /** @return Collection<int, AllianceBuilding> */
    public function getBuildings(): Collection
    {
        return $this->buildings;
    }

    public function buildingLevel(AllianceBuildingType $type): int
    {
        return $this->building($type)?->getLevel() ?? 0;
    }

    public function building(AllianceBuildingType $type): ?AllianceBuilding
    {
        return $this->buildings->findFirst(static fn(int $key, AllianceBuilding $building): bool => $building->getType() === $type);
    }

    /** Monte un bâtiment d'un niveau (le crée au niveau 1) */
    public function raise(AllianceBuildingType $type): AllianceBuilding
    {
        $building = $this->building($type);
        if (null === $building) {
            $building = new AllianceBuilding($this, $type);
            $this->buildings->add($building);
        }
        $building->levelUp();

        return $building;
    }

    public function __toString(): string
    {
        return \sprintf('%s (%s)', $this->name, $this->planet->getAddress());
    }
}
