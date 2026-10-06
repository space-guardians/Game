<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FleetRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Flotte d'un empire : des vaisseaux prélevés dans l'inventaire d'une planète, qui se déplacent et agissent ensemble.
 * Pour l'instant stationnée sur sa planète de constitution ; la position et les déplacements arrivent avec le moteur
 * de trajectoire et la suite d'ordres (#32, #33).
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

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        private string $name,
        /** Planète où la flotte est stationnée */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Planet $planet,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->name = trim($name);
        $this->ships = new ArrayCollection();
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

    public function getPlanet(): Planet
    {
        return $this->planet;
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
