<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeleportLinkRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Liaison entre deux portes de téléportation d'une même alliance (§4.10) : une flotte entrant par l'une ressort par
 * l'autre après le délai de transit. Une porte n'est reliée qu'à une seule autre.
 */
#[ORM\Entity(repositoryClass: TeleportLinkRepository::class)]
#[Auditable]
class TeleportLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Alliance $alliance,
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private readonly AlliancePlanet $gateA,
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private readonly AlliancePlanet $gateB,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if ($gateA === $gateB) {
            throw new \InvalidArgumentException('Une porte ne se relie pas à elle-même.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlliance(): Alliance
    {
        return $this->alliance;
    }

    public function getGateA(): AlliancePlanet
    {
        return $this->gateA;
    }

    public function getGateB(): AlliancePlanet
    {
        return $this->gateB;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Porte de sortie pour une flotte entrant par $gate */
    public function exitFor(AlliancePlanet $gate): AlliancePlanet
    {
        return match ($gate) {
            $this->gateA => $this->gateB,
            $this->gateB => $this->gateA,
            default => throw new \InvalidArgumentException(\sprintf('La porte %s ne fait pas partie de cette liaison.', $gate)),
        };
    }

    public function __toString(): string
    {
        return \sprintf('%s ↔ %s', $this->gateA, $this->gateB);
    }
}
