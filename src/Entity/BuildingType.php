<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Economy\BuildingEffect;
use App\Model\Economy\Resources;
use App\Repository\BuildingTypeRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Type de bâtiment (contenu de jeu, réglable dans le panneau) : coût de base et sa progression par niveau,
 * paramètres de l'effet. Les formules sont dans BuildingRules ; l'effet choisit laquelle s'applique.
 *
 * @see §4.3 du cahier des charges
 */
#[ORM\Entity(repositoryClass: BuildingTypeRepository::class)]
#[ORM\UniqueConstraint(name: 'building_type_code_unique', fields: ['code'])]
#[Auditable]
final class BuildingType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Coût du niveau 1 ; le niveau n coûte ce montant × costFactor^(n − 1) */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $baseCostMetal = 0.0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $baseCostCrystal = 0.0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $baseCostDeuterium = 0.0;

    #[ORM\Column]
    #[Assert\GreaterThanOrEqual(1, message: 'Le facteur de coût doit être au moins 1 : un niveau ne coûte pas moins que le précédent.')]
    private float $costFactor = 1.5;

    /** Production, énergie ou stockage de base de la formule (ex. 30 de métal par heure pour la mine de métal) */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $effectBase = 0.0;

    /** Croissance par niveau de la production ou de l'énergie (base × niveau × croissance^niveau) */
    #[ORM\Column]
    #[Assert\GreaterThanOrEqual(1)]
    private float $effectGrowth = 1.1;

    /** Énergie consommée : base × niveau × 1,1^niveau */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $energyConsumption = 0.0;

    /** Deutérium consommé par heure (centrale à fusion) : base × niveau × 1,1^niveau */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $deuteriumConsumption = 0.0;

    /** Influence de la température moyenne T de la planète : effet × (temperatureBase + temperatureCoefficient × T) */
    #[ORM\Column]
    private float $temperatureBase = 1.0;

    #[ORM\Column]
    private float $temperatureCoefficient = 0.0;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function __construct(
        /** Identifiant stable, utilisé par le code et les échanges (ex. « metal_mine ») */
        #[ORM\Column(length: 40)]
        private readonly string $code,
        #[ORM\Column(length: 60)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        private string $name,
        #[ORM\Column(length: 30, enumType: BuildingEffect::class)]
        private readonly BuildingEffect $effect,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getEffect(): BuildingEffect
    {
        return $this->effect;
    }

    public function getBaseCost(): Resources
    {
        return new Resources($this->baseCostMetal, $this->baseCostCrystal, $this->baseCostDeuterium);
    }

    public function setBaseCost(Resources $cost): void
    {
        $this->baseCostMetal = $cost->metal;
        $this->baseCostCrystal = $cost->crystal;
        $this->baseCostDeuterium = $cost->deuterium;
    }

    public function getBaseCostMetal(): float
    {
        return $this->baseCostMetal;
    }

    public function setBaseCostMetal(float $value): void
    {
        $this->baseCostMetal = $value;
    }

    public function getBaseCostCrystal(): float
    {
        return $this->baseCostCrystal;
    }

    public function setBaseCostCrystal(float $value): void
    {
        $this->baseCostCrystal = $value;
    }

    public function getBaseCostDeuterium(): float
    {
        return $this->baseCostDeuterium;
    }

    public function setBaseCostDeuterium(float $value): void
    {
        $this->baseCostDeuterium = $value;
    }

    public function getCostFactor(): float
    {
        return $this->costFactor;
    }

    public function setCostFactor(float $costFactor): void
    {
        $this->costFactor = $costFactor;
    }

    public function getEffectBase(): float
    {
        return $this->effectBase;
    }

    public function setEffectBase(float $effectBase): void
    {
        $this->effectBase = $effectBase;
    }

    public function getEffectGrowth(): float
    {
        return $this->effectGrowth;
    }

    public function setEffectGrowth(float $effectGrowth): void
    {
        $this->effectGrowth = $effectGrowth;
    }

    public function getEnergyConsumption(): float
    {
        return $this->energyConsumption;
    }

    public function setEnergyConsumption(float $energyConsumption): void
    {
        $this->energyConsumption = $energyConsumption;
    }

    public function getDeuteriumConsumption(): float
    {
        return $this->deuteriumConsumption;
    }

    public function setDeuteriumConsumption(float $deuteriumConsumption): void
    {
        $this->deuteriumConsumption = $deuteriumConsumption;
    }

    public function getTemperatureBase(): float
    {
        return $this->temperatureBase;
    }

    public function setTemperatureBase(float $temperatureBase): void
    {
        $this->temperatureBase = $temperatureBase;
    }

    public function getTemperatureCoefficient(): float
    {
        return $this->temperatureCoefficient;
    }

    public function setTemperatureCoefficient(float $temperatureCoefficient): void
    {
        $this->temperatureCoefficient = $temperatureCoefficient;
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
