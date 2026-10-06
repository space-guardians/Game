<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Economy\Resources;
use App\Repository\BuildingQueueItemRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Construction en cours sur une planète : une seule à la fois (contrainte unique), supprimée quand elle se termine.
 * Le coût payé est conservé pour le remboursement en cas d'annulation (#22).
 *
 * @see §4.3 du cahier des charges
 */
#[ORM\Entity(repositoryClass: BuildingQueueItemRepository::class)]
#[ORM\UniqueConstraint(name: 'building_queue_item_planet_unique', fields: ['planet'])]
#[Auditable]
final class BuildingQueueItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private float $paidMetal;

    #[ORM\Column]
    private float $paidCrystal;

    #[ORM\Column]
    private float $paidDeuterium;

    /** Événement qui terminera la construction */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScheduledEvent $event = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Planet $planet,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private readonly BuildingType $type,
        /** Niveau atteint à la fin de la construction */
        #[ORM\Column]
        private readonly int $targetLevel,
        Resources $paid,
        #[ORM\Column]
        private readonly \DateTimeImmutable $startedAt,
        #[ORM\Column]
        private readonly \DateTimeImmutable $endsAt,
    ) {
        $this->paidMetal = $paid->metal;
        $this->paidCrystal = $paid->crystal;
        $this->paidDeuterium = $paid->deuterium;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function getType(): BuildingType
    {
        return $this->type;
    }

    public function getTargetLevel(): int
    {
        return $this->targetLevel;
    }

    public function getPaid(): Resources
    {
        return new Resources($this->paidMetal, $this->paidCrystal, $this->paidDeuterium);
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getDurationSeconds(): int
    {
        return $this->endsAt->getTimestamp() - $this->startedAt->getTimestamp();
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
        return \sprintf('%s niv. %d %s', $this->type->getName(), $this->targetLevel, $this->planet);
    }
}
