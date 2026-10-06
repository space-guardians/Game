<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Scheduling\ScheduledEventStatus;
use App\Repository\ScheduledEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement de jeu à résoudre à une échéance (fin de construction, arrivée de flotte…). La base fait foi : le
 * message différé n'est qu'un réveil, rattrapé si besoin par la vérification périodique.
 *
 * @see §5.2 du cahier des charges
 */
#[ORM\Entity(repositoryClass: ScheduledEventRepository::class)]
#[ORM\Index(name: 'scheduled_event_due_idx', fields: ['status', 'dueAt'])]
final class ScheduledEvent implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ScheduledEventStatus::class)]
    private ScheduledEventStatus $status = ScheduledEventStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /**
     * @param array<string, mixed> $payload données propres au type (identifiants, quantités…)
     */
    public function __construct(
        /** Type, qui désigne le gestionnaire chargé de le résoudre (ScheduledEventHandler::type()) */
        #[ORM\Column(length: 60)]
        private readonly string $type,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $dueAt,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
        /** Planète concernée : ses événements sont résolus un par un, dans l'ordre des échéances */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private readonly ?Planet $planet = null,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $payload = [],
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPlanet(): ?Planet
    {
        return $this->planet;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): ScheduledEventStatus
    {
        return $this->status;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return ScheduledEventStatus::Pending === $this->status && $this->dueAt <= $now;
    }

    public function markDone(\DateTimeImmutable $now): void
    {
        $this->assertPending();
        $this->status = ScheduledEventStatus::Done;
        $this->resolvedAt = $now;
        ++$this->attempts;
    }

    /** Une résolution qui échoue n'est pas retentée automatiquement : elle reste visible pour l'exploitation */
    public function markFailed(string $error, \DateTimeImmutable $now): void
    {
        $this->assertPending();
        $this->status = ScheduledEventStatus::Failed;
        $this->error = $error;
        $this->resolvedAt = $now;
        ++$this->attempts;
    }

    /** Annulation par le joueur (construction annulée…) : le réveil éventuel n'aura plus rien à faire */
    public function cancel(\DateTimeImmutable $now): void
    {
        $this->assertPending();
        $this->status = ScheduledEventStatus::Cancelled;
        $this->resolvedAt = $now;
    }

    /**
     * Relance depuis le panneau d'administration (§5.6.1) d'un événement en échec, une fois la cause corrigée :
     * il repasse en attente, son échéance passée le rend aussitôt résoluble. L'erreur précédente est effacée,
     * le nombre de tentatives conservé.
     */
    public function retry(): void
    {
        if (ScheduledEventStatus::Failed !== $this->status) {
            throw new \LogicException(\sprintf('Seul un événement en échec peut être relancé (#%d : %s).', (int) $this->id, $this->status->value));
        }
        $this->status = ScheduledEventStatus::Pending;
        $this->error = null;
        $this->resolvedAt = null;
    }

    /** En attente, échu depuis plus de $tolerance secondes : ni le réveil ni la vérification périodique ne l'ont résolu */
    public function isLate(\DateTimeImmutable $now, int $tolerance): bool
    {
        return ScheduledEventStatus::Pending === $this->status && $this->dueAt->getTimestamp() < $now->getTimestamp() - $tolerance;
    }

    public function __toString(): string
    {
        return \sprintf('%s #%d', $this->type, (int) $this->id);
    }

    private function assertPending(): void
    {
        if (ScheduledEventStatus::Pending !== $this->status) {
            throw new \LogicException(\sprintf('L\'événement #%d n\'est plus en attente (%s).', (int) $this->id, $this->status->value));
        }
    }
}
