<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Economy\Resources;
use App\Repository\TechnologyRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Technologie de l'arbre de recherche (contenu de jeu, réglable dans le panneau) : coût de base et sa progression
 * par niveau. Les niveaux atteints sont ceux de l'empire (Research), pas d'une planète.
 *
 * @see §4.4 du cahier des charges
 */
#[ORM\Entity(repositoryClass: TechnologyRepository::class)]
#[ORM\UniqueConstraint(name: 'technology_code_unique', fields: ['code'])]
#[Auditable]
final class Technology implements \Stringable
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
    private float $costFactor = 2.0;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function __construct(
        /** Identifiant stable, utilisé par le code et les échanges (ex. « energy ») */
        #[ORM\Column(length: 40)]
        private readonly string $code,
        #[ORM\Column(length: 60)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        private string $name,
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
