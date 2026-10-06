<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrerequisiteRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Prérequis croisé (contenu de jeu, réglable dans le panneau) : pour construire un bâtiment ou rechercher une
 * technologie (la cible), il faut un bâtiment ou une technologie (le requis) à un niveau minimal.
 * Ex. : centrale à fusion → synthétiseur de deutérium niveau 5 et énergie niveau 3.
 * Un bâtiment requis s'entend sur la planète concernée, une technologie au niveau de l'empire.
 * Une seule cible et un seul requis : contraintes CHECK posées par la migration (Doctrine ne les déclare pas).
 *
 * @see §4.4 du cahier des charges
 */
#[ORM\Entity(repositoryClass: PrerequisiteRepository::class)]
#[Auditable]
final class Prerequisite implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Cible : bâtiment… */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?BuildingType $targetBuilding = null;

    /** … ou technologie */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Technology $targetTechnology = null;

    /** Requis : bâtiment… */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?BuildingType $requiredBuilding = null;

    /** … ou technologie */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Technology $requiredTechnology = null;

    #[ORM\Column]
    #[Assert\Positive(message: 'Le niveau requis doit être d’au moins 1.')]
    private int $level = 1;

    public static function of(BuildingType|Technology $target, BuildingType|Technology $required, int $level): self
    {
        $prerequisite = new self();
        $prerequisite->setTarget($target);
        $prerequisite->setRequired($required);
        $prerequisite->setLevel($level);

        return $prerequisite;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarget(): BuildingType|Technology|null
    {
        return $this->targetBuilding ?? $this->targetTechnology;
    }

    public function setTarget(BuildingType|Technology $target): void
    {
        $this->targetBuilding = $target instanceof BuildingType ? $target : null;
        $this->targetTechnology = $target instanceof Technology ? $target : null;
    }

    public function getRequired(): BuildingType|Technology|null
    {
        return $this->requiredBuilding ?? $this->requiredTechnology;
    }

    public function setRequired(BuildingType|Technology $required): void
    {
        $this->requiredBuilding = $required instanceof BuildingType ? $required : null;
        $this->requiredTechnology = $required instanceof Technology ? $required : null;
    }

    /** Le requis est-il un bâtiment (sinon une technologie) ? */
    public function requiresBuilding(): bool
    {
        return null !== $this->requiredBuilding;
    }

    /** Code du bâtiment ou de la technologie requis */
    public function getRequiredCode(): string
    {
        return $this->getRequired()?->getCode() ?? '';
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }

    // Champs séparés pour le formulaire du panneau, qui choisit l'une ou l'autre forme de la cible et du requis

    public function getTargetBuilding(): ?BuildingType
    {
        return $this->targetBuilding;
    }

    public function setTargetBuilding(?BuildingType $targetBuilding): void
    {
        $this->targetBuilding = $targetBuilding;
    }

    public function getTargetTechnology(): ?Technology
    {
        return $this->targetTechnology;
    }

    public function setTargetTechnology(?Technology $targetTechnology): void
    {
        $this->targetTechnology = $targetTechnology;
    }

    public function getRequiredBuilding(): ?BuildingType
    {
        return $this->requiredBuilding;
    }

    public function setRequiredBuilding(?BuildingType $requiredBuilding): void
    {
        $this->requiredBuilding = $requiredBuilding;
    }

    public function getRequiredTechnology(): ?Technology
    {
        return $this->requiredTechnology;
    }

    public function setRequiredTechnology(?Technology $requiredTechnology): void
    {
        $this->requiredTechnology = $requiredTechnology;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ((null === $this->targetBuilding) === (null === $this->targetTechnology)) {
            $context->buildViolation('Choisissez la cible : un bâtiment ou une technologie, pas les deux.')->atPath('targetBuilding')->addViolation();
        }
        if ((null === $this->requiredBuilding) === (null === $this->requiredTechnology)) {
            $context->buildViolation('Choisissez le requis : un bâtiment ou une technologie, pas les deux.')->atPath('requiredBuilding')->addViolation();
        }
        if (null !== $this->getTarget() && $this->getTarget() === $this->getRequired()) {
            $context->buildViolation('Un bâtiment ou une technologie ne peut pas se requérir lui-même.')->atPath('requiredBuilding')->addViolation();
        }
    }

    public function __toString(): string
    {
        return \sprintf('%s → %s niveau %d', $this->getTarget() ?? '?', $this->getRequired() ?? '?', $this->level);
    }
}
