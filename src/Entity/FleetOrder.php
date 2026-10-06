<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ordre du carnet d'une flotte : « se déplacer vers une position, puis effectuer une action » (§4.6). Les ordres
 * s'exécutent dans l'ordre de leur rang.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'fleet_order_rank_unique', fields: ['fleet', 'rank'])]
#[Auditable]
final class FleetOrder implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: FleetOrderStatus::class)]
    private FleetOrderStatus $status = FleetOrderStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** Raison d'un abandon (action impossible à l'arrivée) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failure = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'orders')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Fleet $fleet,
        /** Rang dans le carnet (1, 2, 3…) */
        #[ORM\Column(name: 'order_rank')]
        private readonly int $rank,
        #[ORM\Embedded(columnPrefix: 'destination_')]
        private readonly SpaceLocation $destination,
        #[ORM\Column(length: 20, enumType: FleetAction::class)]
        private readonly FleetAction $action,
        /** Libellé de la destination au moment de l'ordre (« 1:42:7 », « système 1:42 »…) */
        #[ORM\Column(length: 60)]
        private readonly string $destinationLabel,
        /** Flotte visée par un ravitaillement */
        #[ORM\Column(nullable: true)]
        private readonly ?int $targetFleetId = null,
    ) {}

    public function getTargetFleetId(): ?int
    {
        return $this->targetFleetId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFleet(): Fleet
    {
        return $this->fleet;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function getDestination(): SpaceLocation
    {
        return $this->destination;
    }

    public function getDestinationLabel(): string
    {
        return $this->destinationLabel;
    }

    public function getAction(): FleetAction
    {
        return $this->action;
    }

    public function getStatus(): FleetOrderStatus
    {
        return $this->status;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function start(): void
    {
        $this->status = FleetOrderStatus::InProgress;
    }

    public function complete(\DateTimeImmutable $at): void
    {
        $this->status = FleetOrderStatus::Done;
        $this->completedAt = $at;
    }

    public function fail(string $reason, \DateTimeImmutable $at): void
    {
        $this->status = FleetOrderStatus::Failed;
        $this->failure = mb_substr($reason, 0, 255);
        $this->completedAt = $at;
    }

    public function __toString(): string
    {
        return \sprintf('%d. %s → %s', $this->rank, $this->action->label(), $this->destinationLabel);
    }
}
