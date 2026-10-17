<?php

declare(strict_types=1);

namespace App\Entity;

use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Flotte d'escorte attachée à un contrat (§4.15) : stationnée au point de départ à l'heure dite, elle voyage avec le
 * convoi ; sa part de la récompense suit le nombre de vaisseaux engagés au départ. Auditée (§5.6.3).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'escort_assignment_fleet_unique', fields: ['quest', 'fleet'])]
#[Auditable]
final class EscortAssignment implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Vaisseaux engagés au départ ; nul tant que le convoi n'est pas parti, ou si l'escorte n'a pas suivi */
    #[ORM\Column(nullable: true)]
    private ?int $ships = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'escorts')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly PlayerQuest $quest,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        /** Nulle si la flotte a disparu */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Fleet $fleet,
        #[ORM\Column]
        private readonly \DateTimeImmutable $joinedAt,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuest(): PlayerQuest
    {
        return $this->quest;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getFleet(): ?Fleet
    {
        return $this->fleet;
    }

    /** Escorte anéantie : la flotte n'existe plus */
    public function forgetFleet(): void
    {
        $this->fleet = null;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getShips(): ?int
    {
        return $this->ships;
    }

    public function setOff(int $ships): void
    {
        $this->ships = $ships;
    }

    public function __toString(): string
    {
        return \sprintf('Escorte de %s (%s)', $this->quest, $this->empire->getName());
    }
}
