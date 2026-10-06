<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Economy\Resources;
use App\Repository\ResearchQueueItemRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Recherche en cours d'un empire : une seule à la fois (contrainte unique), supprimée quand elle se termine.
 * La planète de lancement est celle qui a payé : l'annulation y rembourse, dans la limite de son stockage (#27).
 *
 * @see §4.4 du cahier des charges
 */
#[ORM\Entity(repositoryClass: ResearchQueueItemRepository::class)]
#[ORM\UniqueConstraint(name: 'research_queue_item_empire_unique', fields: ['empire'])]
#[Auditable]
final class ResearchQueueItem implements \Stringable
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

    /** Événement qui terminera la recherche */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScheduledEvent $event = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        /** Planète de lancement, qui a payé la recherche */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Planet $planet,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private readonly Technology $technology,
        /** Niveau atteint à la fin de la recherche */
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

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function getTechnology(): Technology
    {
        return $this->technology;
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
        return \sprintf('%s niv. %d (%s)', $this->technology->getName(), $this->targetLevel, $this->empire->getName());
    }
}
