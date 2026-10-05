<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Admin\AuditAction;
use App\Repository\AdminAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entrée du journal d'audit de l'administration : qui a fait quoi, quand, sur quoi, avec les valeurs avant/après.
 * Immuable : aucune méthode de modification, et un trigger PostgreSQL refuse toute mise à jour de la table.
 * L'auteur est recopié (identifiant et e-mail) plutôt que lié, pour survivre à la suppression de son compte.
 *
 * @see §5.6.2 du cahier des charges
 */
#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class)]
#[ORM\Index(name: 'admin_audit_log_occurred_at_idx', fields: ['occurredAt'])]
#[ORM\Index(name: 'admin_audit_log_subject_idx', fields: ['subjectType', 'subjectId'])]
final class AdminAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes champ => [avant, après] ; null avant une création,
     *                                                           null après une suppression
     */
    public function __construct(
        #[ORM\Column]
        private readonly \DateTimeImmutable $occurredAt,
        /** Null pour une action sans compte connecté (tâche de fond déclenchée sans auteur) */
        #[ORM\Column(nullable: true)]
        private readonly ?int $actorId,
        #[ORM\Column(length: 180)]
        private readonly string $actorEmail,
        #[ORM\Column(length: 20, enumType: AuditAction::class)]
        private readonly AuditAction $action,
        /** Nom court de l'entité visée (« Galaxy ») ou de l'objet de l'action */
        #[ORM\Column(length: 100)]
        private readonly string $subjectType,
        #[ORM\Column(length: 64, nullable: true)]
        private readonly ?string $subjectId,
        /** Libellé lisible au moment de l'action (« Galaxie 1 — Orion »), conservé après suppression */
        #[ORM\Column(length: 255)]
        private readonly string $subjectLabel,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $changes = [],
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function getActorEmail(): string
    {
        return $this->actorEmail;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function __toString(): string
    {
        return \sprintf('%s — %s', $this->action->label(), $this->subjectLabel);
    }
}
