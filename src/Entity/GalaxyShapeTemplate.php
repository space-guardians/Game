<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\GalaxyShapeTemplateRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Gabarit de forme d'une galaxie spirale, édité depuis le panneau d'administration : il permet de générer de
 * nouvelles galaxies sans toucher au code. Ses bornes reprennent celles de SpiralGalaxyShape.
 *
 * @see §2.2 et §5.5 du cahier des charges
 */
#[ORM\Entity(repositoryClass: GalaxyShapeTemplateRepository::class)]
#[ORM\UniqueConstraint(name: 'galaxy_shape_template_name_unique', fields: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'Un gabarit porte déjà ce nom.')]
#[Auditable]
final class GalaxyShapeTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Donnez un nom au gabarit.')]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 12, notInRangeMessage: 'Une galaxie spirale a entre {{ min }} et {{ max }} branches.')]
    private int $arms = 4;

    /** Angle ajouté (radians) par unité de ln(1 + r / rayon du bulbe) */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 10)]
    private float $armTightness = 2.5;

    /** Écart-type angulaire d'une branche, en radians */
    #[ORM\Column]
    #[Assert\Range(min: 0.05, max: 1.5)]
    private float $armWidth = 0.25;

    #[ORM\Column]
    #[Assert\Range(min: 100, max: 20_000)]
    private float $coreRadius = 1_500.0;

    #[ORM\Column]
    #[Assert\Range(min: 500, max: 50_000)]
    private float $diskScale = 5_000.0;

    /** De 0 (vide entre les branches) à 1 (aussi dense que les branches) */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 1)]
    private float $interArmDensity = 0.03;

    /** Créé avec les réglages par défaut de SpiralGalaxyShape, ajustés ensuite */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function fromShape(string $name, SpiralGalaxyShape $shape): self
    {
        $template = new self($name);
        $template->arms = $shape->arms;
        $template->armTightness = $shape->armTightness;
        $template->armWidth = $shape->armWidth;
        $template->coreRadius = $shape->coreRadius;
        $template->diskScale = $shape->diskScale;
        $template->interArmDensity = $shape->interArmDensity;

        return $template;
    }

    /** Forme utilisée par l'algorithme de génération */
    public function toShape(): SpiralGalaxyShape
    {
        return new SpiralGalaxyShape(
            $this->arms,
            $this->armTightness,
            $this->armWidth,
            $this->coreRadius,
            $this->diskScale,
            $this->interArmDensity,
        );
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getArms(): int
    {
        return $this->arms;
    }

    public function setArms(int $arms): void
    {
        $this->arms = $arms;
    }

    public function getArmTightness(): float
    {
        return $this->armTightness;
    }

    public function setArmTightness(float $armTightness): void
    {
        $this->armTightness = $armTightness;
    }

    public function getArmWidth(): float
    {
        return $this->armWidth;
    }

    public function setArmWidth(float $armWidth): void
    {
        $this->armWidth = $armWidth;
    }

    public function getCoreRadius(): float
    {
        return $this->coreRadius;
    }

    public function setCoreRadius(float $coreRadius): void
    {
        $this->coreRadius = $coreRadius;
    }

    public function getDiskScale(): float
    {
        return $this->diskScale;
    }

    public function setDiskScale(float $diskScale): void
    {
        $this->diskScale = $diskScale;
    }

    public function getInterArmDensity(): float
    {
        return $this->interArmDensity;
    }

    public function setInterArmDensity(float $interArmDensity): void
    {
        $this->interArmDensity = $interArmDensity;
    }
}
