<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Fleet\FleetStatus;
use App\Model\Economy\Resources;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Flotte d'un empire : des vaisseaux prélevés dans l'inventaire d'une planète, qui se déplacent et agissent ensemble
 * en exécutant leur carnet d'ordres (§4.6). Elle a une position (planète, système, point de l'espace), un état et une
 * cargaison.
 *
 * @see §4.5, §4.6 du cahier des charges
 */
#[ORM\Entity(repositoryClass: FleetRepository::class)]
#[Auditable]
final class Fleet implements \Stringable
{
    public const int NAME_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, FleetShip> */
    #[ORM\OneToMany(targetEntity: FleetShip::class, mappedBy: 'fleet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $ships;

    /** @var Collection<int, FleetOrder> carnet d'ordres de la mission en cours ou de la dernière */
    #[ORM\OneToMany(targetEntity: FleetOrder::class, mappedBy: 'fleet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['rank' => 'ASC'])]
    private Collection $orders;

    #[ORM\Column(length: 20, enumType: FleetStatus::class)]
    private FleetStatus $status = FleetStatus::Stationed;

    /** Formation de combat (§4.7) ; créée avec la flotte, ou à la première consultation pour les plus anciennes */
    #[ORM\OneToOne(targetEntity: Formation::class, mappedBy: 'fleet', cascade: ['persist', 'remove'])]
    private ?Formation $formation = null;

    /** Planète où la flotte est stationnée ; null en vol ou hors planète */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Planet $planet;

    /** Position actuelle (stationnée), ou point de départ du déplacement en cours */
    #[ORM\Embedded(columnPrefix: 'location_')]
    private SpaceLocation $location;

    #[ORM\Column]
    private float $cargoMetal = 0.0;

    #[ORM\Column]
    private float $cargoCrystal = 0.0;

    #[ORM\Column]
    private float $cargoDeuterium = 0.0;

    /** Deutérium dans les réservoirs, brûlé par les trajets (§4.6.3) ; distinct de la cargaison */
    #[ORM\Column]
    private float $fuel = 0.0;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        private string $name,
        /** Planète de constitution, où la flotte est d'abord stationnée */
        Planet $planet,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->name = trim($name);
        $this->ships = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->planet = $planet;
        $this->location = SpaceLocation::of(SpacePosition::planet($planet));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPlanet(): ?Planet
    {
        return $this->planet;
    }

    public function getFormation(): ?Formation
    {
        return $this->formation;
    }

    public function setFormation(Formation $formation): void
    {
        if ($formation->getFleet() !== $this) {
            throw new \InvalidArgumentException('Cette formation est celle d’une autre flotte.');
        }
        $this->formation = $formation;
    }

    /**
     * Vaisseaux de la flotte, par code de type.
     *
     * @return array<string, int>
     */
    public function shipCounts(): array
    {
        $counts = [];
        foreach ($this->ships as $ships) {
            $code = $ships->getType()->getCode();
            $counts[$code] = ($counts[$code] ?? 0) + $ships->getQuantity();
        }

        return $counts;
    }

    public function getStatus(): FleetStatus
    {
        return $this->status;
    }

    public function isStationed(): bool
    {
        return FleetStatus::Stationed === $this->status;
    }

    /** Stationnée sur une planète de son empire : elle peut y charger, y être dissoute */
    public function isAtHome(): bool
    {
        return $this->isStationed() && $this->planet?->getOwner() === $this->empire;
    }

    public function getLocation(): SpaceLocation
    {
        return $this->location;
    }

    /** Départ d'un déplacement : la flotte quitte sa position */
    public function depart(): void
    {
        $this->status = FleetStatus::InFlight;
        $this->planet = null;
    }

    /** Arrivée à une position (planète éventuelle), avant l'action de l'ordre */
    public function arriveAt(SpaceLocation $location, ?Planet $planet): void
    {
        $this->location = $location;
        $this->planet = $planet;
    }

    /** Fin de mission : la flotte reste là où elle est (pas de retour implicite, §4.6) */
    public function station(): void
    {
        $this->status = FleetStatus::Stationed;
    }

    /** Panne de carburant : immobilisée à la position où le réservoir s'est vidé, jusqu'à un ravitaillement */
    public function strand(SpaceLocation $location): void
    {
        $this->location = $location;
        $this->planet = null;
        $this->fuel = 0.0;
        $this->status = FleetStatus::Stranded;
    }

    public function isStranded(): bool
    {
        return FleetStatus::Stranded === $this->status;
    }

    public function getFuel(): float
    {
        return $this->fuel;
    }

    /** Capacité totale des réservoirs */
    public function tankCapacity(): int
    {
        $capacity = 0;
        foreach ($this->ships as $ships) {
            $capacity += $ships->getType()->getFuelCapacity() * $ships->getQuantity();
        }

        return $capacity;
    }

    /** Remplit les réservoirs ; renvoie le carburant effectivement accepté (dans la limite de la capacité) */
    public function refuel(float $amount): float
    {
        $accepted = max(0.0, min($amount, $this->tankCapacity() - $this->fuel));
        $this->fuel += $accepted;
        // Ravitaillée, une flotte en panne peut repartir : elle redevient stationnée là où elle est
        if ($accepted > 0.0 && $this->isStranded()) {
            $this->status = FleetStatus::Stationed;
        }

        return $accepted;
    }

    /** Brûle le carburant d'un trajet (au plus ce qui reste) */
    public function burn(float $amount): void
    {
        $this->fuel = max(0.0, $this->fuel - $amount);
    }

    /** Vide les réservoirs (dissolution : le carburant rejoint la planète) */
    public function drain(): float
    {
        $fuel = $this->fuel;
        $this->fuel = 0.0;

        return $fuel;
    }

    /** Retire du deutérium de la cargaison (livraison d'un ravitaillement) ; renvoie la quantité retirée */
    public function takeCargoDeuterium(float $amount): float
    {
        $taken = max(0.0, min($amount, $this->cargoDeuterium));
        $this->cargoDeuterium -= $taken;

        return $taken;
    }

    public function getCargo(): Resources
    {
        return new Resources($this->cargoMetal, $this->cargoCrystal, $this->cargoDeuterium);
    }

    public function load(Resources $resources): void
    {
        $cargo = $this->getCargo()->plus($resources);
        $total = $cargo->metal + $cargo->crystal + $cargo->deuterium;
        if ($total > $this->cargo() + 1e-6) {
            throw new \InvalidArgumentException(\sprintf('Cargaison trop lourde : %d pour une capacité de %d.', (int) ceil($total), $this->cargo()));
        }
        [$this->cargoMetal, $this->cargoCrystal, $this->cargoDeuterium] = [$cargo->metal, $cargo->crystal, $cargo->deuterium];
    }

    /** Décharge toute la cargaison */
    public function unload(): Resources
    {
        $cargo = $this->getCargo();
        $this->cargoMetal = $this->cargoCrystal = $this->cargoDeuterium = 0.0;

        return $cargo;
    }

    /** @return Collection<int, FleetOrder> */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    /** Nouveau carnet d'ordres : remplace celui de la mission précédente */
    public function replaceOrders(): void
    {
        $this->orders->clear();
    }

    public function addOrder(FleetOrder $order): void
    {
        $this->orders->add($order);
    }

    public function getNextPendingOrder(): ?FleetOrder
    {
        foreach ($this->orders as $order) {
            if (FleetOrderStatus::Pending === $order->getStatus()) {
                return $order;
            }
        }

        return null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, FleetShip> */
    public function getShips(): Collection
    {
        return $this->ships;
    }

    public function addShips(ShipType $type, int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('On ajoute au moins un vaisseau à une flotte.');
        }
        $ships = $this->ships->findFirst(static fn(int $key, FleetShip $ships): bool => $ships->getType() === $type);
        if (null === $ships) {
            $this->ships->add(new FleetShip($this, $type, $quantity));

            return;
        }
        $ships->setQuantity($ships->getQuantity() + $quantity);
    }

    /** Retire des vaisseaux de la flotte (colonisateur consommé, pertes…), formation comprise */
    public function removeShips(ShipType $type, int $quantity): void
    {
        $ships = $this->ships->findFirst(static fn(int $key, FleetShip $ships): bool => $ships->getType() === $type);
        $available = $ships?->getQuantity() ?? 0;
        if ($quantity < 1 || $quantity > $available) {
            throw new \InvalidArgumentException(\sprintf('Impossible de retirer %d × %s : %d dans la flotte.', $quantity, $type->getName(), $available));
        }
        if ($quantity === $available) {
            $this->ships->removeElement($ships);
        } else {
            $ships->setQuantity($available - $quantity);
        }
        $this->formation?->withdraw($type, $quantity);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->shipCount();
    }

    public function shipCount(?ShipType $type = null): int
    {
        $count = 0;
        foreach ($this->ships as $ships) {
            if (null === $type || $ships->getType() === $type) {
                $count += $ships->getQuantity();
            }
        }

        return $count;
    }

    /** Capacité de cargo totale */
    public function cargo(): int
    {
        $cargo = 0;
        foreach ($this->ships as $ships) {
            $cargo += $ships->getType()->getCargo() * $ships->getQuantity();
        }

        return $cargo;
    }

    /** Vitesse de base du vaisseau le plus lent, qui fixe celle de la flotte (§4.6.1) ; 0 pour une flotte vide */
    public function slowestSpeed(): int
    {
        $speeds = array_map(static fn(FleetShip $ships): int => $ships->getType()->getSpeed(), $this->ships->toArray());

        return [] === $speeds ? 0 : min($speeds);
    }

    public function __toString(): string
    {
        return \sprintf('%s (%s)', $this->name, $this->empire->getName());
    }
}
