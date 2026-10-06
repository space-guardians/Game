<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Economy\Resources;
use App\Repository\PlanetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Planète d'un système stellaire. Sa position est locale au système (orbite + angle),
 * jamais une coordonnée globale.
 *
 * @see §2.2 du cahier des charges
 */
#[ORM\Entity(repositoryClass: PlanetRepository::class)]
#[ORM\UniqueConstraint(name: 'planet_system_orbit_unique', fields: ['system', 'position.orbit'])]
final class Planet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Empire propriétaire ; libre tant que null */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Empire $owner = null;

    /** Stock à la date resourcesUpdatedAt ; le stock courant se calcule à la demande (PlanetResources) */
    #[ORM\Column(options: ['default' => 0])]
    private float $metal = 0.0;

    #[ORM\Column(options: ['default' => 0])]
    private float $crystal = 0.0;

    #[ORM\Column(options: ['default' => 0])]
    private float $deuterium = 0.0;

    /** Dernière consolidation du stock ; null tant que la planète ne produit pas (sans propriétaire) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resourcesUpdatedAt = null;

    /** @var Collection<int, PlanetBuilding> */
    #[ORM\OneToMany(targetEntity: PlanetBuilding::class, mappedBy: 'planet', cascade: ['persist'])]
    private Collection $buildings;

    /** @var Collection<int, PlanetShip> vaisseaux stationnés (inventaire) */
    #[ORM\OneToMany(targetEntity: PlanetShip::class, mappedBy: 'planet', cascade: ['persist'])]
    private Collection $ships;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'planets')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private StarSystem $system,
        #[ORM\Embedded(columnPrefix: false)]
        private OrbitalPosition $position,
        /** Température moyenne en °C, fixée à la création : influence l'énergie solaire et le deutérium */
        #[ORM\Column]
        private int $temperature,
    ) {
        $system->addPlanet($this);
        $this->buildings = new ArrayCollection();
        $this->ships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSystem(): StarSystem
    {
        return $this->system;
    }

    public function getPosition(): OrbitalPosition
    {
        return $this->position;
    }

    public function getTemperature(): int
    {
        return $this->temperature;
    }

    public function getOwner(): ?Empire
    {
        return $this->owner;
    }

    /** @return Collection<int, PlanetBuilding> */
    public function getBuildings(): Collection
    {
        return $this->buildings;
    }

    public function buildingLevel(BuildingType $type): int
    {
        return $this->findBuilding($type)?->getLevel() ?? 0;
    }

    /** Fixe le niveau d'un bâtiment ; consolider les ressources avant (la production change avec le niveau) */
    public function setBuildingLevel(BuildingType $type, int $level): void
    {
        $building = $this->findBuilding($type);
        if (null === $building) {
            $building = new PlanetBuilding($this, $type);
            $this->buildings->add($building);
        }
        $building->setLevel($level);
    }

    private function findBuilding(BuildingType $type): ?PlanetBuilding
    {
        return $this->buildings->findFirst(static fn(int $key, PlanetBuilding $building): bool => $building->getType() === $type);
    }

    /** @return Collection<int, PlanetShip> */
    public function getShips(): Collection
    {
        return $this->ships;
    }

    public function shipCount(ShipType $type): int
    {
        return $this->findShips($type)?->getQuantity() ?? 0;
    }

    /** Ajoute des vaisseaux à l'inventaire (livraison du chantier spatial, retour de flotte…) */
    public function addShips(ShipType $type, int $quantity): void
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('On n\'ajoute pas un nombre négatif de vaisseaux.');
        }
        $ships = $this->findShips($type);
        if (null === $ships) {
            $ships = new PlanetShip($this, $type);
            $this->ships->add($ships);
        }
        $ships->setQuantity($ships->getQuantity() + $quantity);
    }

    private function findShips(ShipType $type): ?PlanetShip
    {
        return $this->ships->findFirst(static fn(int $key, PlanetShip $ships): bool => $ships->getType() === $type);
    }

    public function getResources(): Resources
    {
        return new Resources($this->metal, $this->crystal, $this->deuterium);
    }

    public function getResourcesUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->resourcesUpdatedAt;
    }

    /** Enregistre le stock calculé à l'instant donné (à la seconde : c'est la précision de la base) */
    public function storeResources(Resources $resources, \DateTimeImmutable $at): void
    {
        $this->metal = $resources->metal;
        $this->crystal = $resources->crystal;
        $this->deuterium = $resources->deuterium;
        $this->resourcesUpdatedAt = $at->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s'));
    }

    public function assignTo(Empire $empire): void
    {
        if (null !== $this->owner && $this->owner !== $empire) {
            throw new \LogicException(\sprintf('La planète %s appartient déjà à l\'empire « %s ».', $this, $this->owner->getName()));
        }
        $this->owner = $empire;
    }

    /** Adresse entre crochets, comme partout dans l'interface (charte §3) */
    public function __toString(): string
    {
        return '[' . $this->getAddress() . ']';
    }

    public function getAddress(): PlanetAddress
    {
        return new PlanetAddress(
            $this->system->getGalaxy()->getNumber(),
            $this->system->getNumber(),
            $this->position->orbit,
        );
    }
}
