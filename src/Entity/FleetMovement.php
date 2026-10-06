<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FleetMovementRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Déplacement en cours d'une flotte vers la destination de son ordre en cours (§4.6) : départ, arrivée planifiée
 * (événement de jeu), pourcentage de vitesse. Supprimé à l'arrivée.
 */
#[ORM\Entity(repositoryClass: FleetMovementRepository::class)]
#[ORM\UniqueConstraint(name: 'fleet_movement_fleet_unique', fields: ['fleet'])]
#[Auditable]
final class FleetMovement implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Événement qui fera arriver la flotte */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScheduledEvent $event = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Fleet $fleet,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly FleetOrder $order,
        #[ORM\Embedded(columnPrefix: 'origin_')]
        private readonly SpaceLocation $origin,
        #[ORM\Column]
        private readonly int $speedPercent,
        #[ORM\Column]
        private readonly \DateTimeImmutable $departedAt,
        #[ORM\Column]
        private readonly \DateTimeImmutable $arrivesAt,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFleet(): Fleet
    {
        return $this->fleet;
    }

    public function getOrder(): FleetOrder
    {
        return $this->order;
    }

    public function getOrigin(): SpaceLocation
    {
        return $this->origin;
    }

    public function getSpeedPercent(): int
    {
        return $this->speedPercent;
    }

    public function getDepartedAt(): \DateTimeImmutable
    {
        return $this->departedAt;
    }

    public function getArrivesAt(): \DateTimeImmutable
    {
        return $this->arrivesAt;
    }

    public function getDurationSeconds(): int
    {
        return $this->arrivesAt->getTimestamp() - $this->departedAt->getTimestamp();
    }

    public function getEvent(): ?ScheduledEvent
    {
        return $this->event;
    }

    public function attachEvent(ScheduledEvent $event): void
    {
        $this->event = $event;
    }

    public function __toString(): string
    {
        return \sprintf('%s → %s', $this->fleet->getName(), $this->order->getDestinationLabel());
    }
}
