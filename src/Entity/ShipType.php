<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Fleet\ShipCategory;
use App\Model\Economy\Resources;
use App\Repository\ShipTypeRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Type de vaisseau (contenu de jeu, réglable dans le panneau) : coût unitaire et caractéristiques. Un type militaire
 * appartient à exactement une classe de combat ; un type civil peut en avoir une ou aucune.
 *
 * @see §4.5 du cahier des charges
 */
#[ORM\Entity(repositoryClass: ShipTypeRepository::class)]
#[ORM\UniqueConstraint(name: 'ship_type_code_unique', fields: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'Ce code est déjà utilisé par un autre type.')]
#[Auditable]
final class ShipType implements \Stringable
{
    /** Code du colonisateur : seul vaisseau capable de fonder une colonie, consommé par l'action « Coloniser » */
    public const string COLONY_SHIP = 'colony_ship';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Une classe utilisée par un type ne se supprime pas (clé étrangère restrictive) */
    #[ORM\ManyToOne]
    private ?ShipClass $shipClass = null;

    /** Technologie de propulsion, qui module la vitesse et la consommation (§4.6) */
    #[ORM\ManyToOne]
    private ?Technology $drive = null;

    /** Coût d'un vaisseau */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $costMetal = 0.0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $costCrystal = 0.0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $costDeuterium = 0.0;

    /** Dégâts infligés par tir */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $attack = 0;

    /** Dégâts absorbés par tir avant d'atteindre la structure */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $shield = 0;

    /** Points de structure (coque) : détruit à 0 */
    #[ORM\Column]
    #[Assert\Positive]
    private int $structure = 1;

    /** Vitesse de base, avant la technologie de propulsion */
    #[ORM\Column]
    #[Assert\Positive]
    private int $speed = 1;

    /** Capacité de cargo (ressources transportables) */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $cargo = 0;

    /** Consommation de deutérium de base d'un trajet (§4.6) */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $fuelConsumption = 0;

    /** Capacité du réservoir (deutérium emportable pour les trajets suivants, §4.6) */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $fuelCapacity = 0;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function __construct(
        /** Identifiant stable, utilisé par le code et les échanges (ex. « light_fighter ») */
        #[ORM\Column(length: 40)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 40)]
        #[Assert\Regex('/^[a-z][a-z0-9_]*$/', message: 'Le code ne contient que des minuscules, chiffres et « _ ».')]
        private string $code = '',
        #[ORM\Column(length: 60)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        private string $name = '',
        #[ORM\Column(length: 20, enumType: ShipCategory::class)]
        private ShipCategory $category = ShipCategory::Military,
    ) {}

    #[Assert\Callback]
    public function validateClass(ExecutionContextInterface $context): void
    {
        if (ShipCategory::Military === $this->category && null === $this->shipClass) {
            $context->buildViolation('Un vaisseau militaire appartient à exactement une classe de combat.')->atPath('shipClass')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCategory(): ShipCategory
    {
        return $this->category;
    }

    public function setCategory(ShipCategory $category): void
    {
        $this->category = $category;
    }

    public function isMilitary(): bool
    {
        return ShipCategory::Military === $this->category;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getShipClass(): ?ShipClass
    {
        return $this->shipClass;
    }

    public function setShipClass(?ShipClass $shipClass): void
    {
        $this->shipClass = $shipClass;
    }

    public function getDrive(): ?Technology
    {
        return $this->drive;
    }

    public function setDrive(?Technology $drive): void
    {
        $this->drive = $drive;
    }

    public function getCost(): Resources
    {
        return new Resources($this->costMetal, $this->costCrystal, $this->costDeuterium);
    }

    public function getCostMetal(): float
    {
        return $this->costMetal;
    }

    public function setCostMetal(float $costMetal): void
    {
        $this->costMetal = $costMetal;
    }

    public function getCostCrystal(): float
    {
        return $this->costCrystal;
    }

    public function setCostCrystal(float $costCrystal): void
    {
        $this->costCrystal = $costCrystal;
    }

    public function getCostDeuterium(): float
    {
        return $this->costDeuterium;
    }

    public function setCostDeuterium(float $costDeuterium): void
    {
        $this->costDeuterium = $costDeuterium;
    }

    public function getAttack(): int
    {
        return $this->attack;
    }

    public function setAttack(int $attack): void
    {
        $this->attack = $attack;
    }

    public function getShield(): int
    {
        return $this->shield;
    }

    public function setShield(int $shield): void
    {
        $this->shield = $shield;
    }

    public function getStructure(): int
    {
        return $this->structure;
    }

    public function setStructure(int $structure): void
    {
        $this->structure = $structure;
    }

    public function getSpeed(): int
    {
        return $this->speed;
    }

    public function setSpeed(int $speed): void
    {
        $this->speed = $speed;
    }

    public function getCargo(): int
    {
        return $this->cargo;
    }

    public function setCargo(int $cargo): void
    {
        $this->cargo = $cargo;
    }

    public function getFuelConsumption(): int
    {
        return $this->fuelConsumption;
    }

    public function setFuelConsumption(int $fuelConsumption): void
    {
        $this->fuelConsumption = $fuelConsumption;
    }

    public function getFuelCapacity(): int
    {
        return $this->fuelCapacity;
    }

    public function setFuelCapacity(int $fuelCapacity): void
    {
        $this->fuelCapacity = $fuelCapacity;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
