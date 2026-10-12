<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Place d'un empire dans une conversation (§4.12.2), avec son état propre : dernier message lu, archivage, suppression
 * de son côté uniquement (la conversation reste visible pour les autres) et départ d'un groupe. Il ne voit que les
 * messages postés pendant sa présence, et après sa dernière suppression.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'conversation_participant_unique', fields: ['conversation', 'empire'])]
#[ORM\Index(name: 'conversation_participant_empire_idx', fields: ['empire'])]
final class ConversationParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    #[ORM\Column]
    private bool $archived = false;

    /** Suppression de son côté : messages antérieurs masqués, conversation masquée jusqu'au prochain message */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clearedAt = null;

    /** Départ d'un groupe : il ne reçoit plus les messages suivants */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'participants')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Conversation $conversation,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\Column]
        private \DateTimeImmutable $joinedAt,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function isActive(): bool
    {
        return null === $this->leftAt;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    /** Un message a-t-il été posté (pendant sa présence) depuis sa dernière lecture ? */
    public function hasUnread(): bool
    {
        $last = $this->conversation->getLastMessageAt();

        return $this->isActive() && $this->isVisible() && $last > ($this->lastReadAt ?? $this->joinedAt->modify('-1 second'));
    }

    /** Visible dans sa liste : pas supprimée de son côté, ou un message est arrivé depuis */
    public function isVisible(): bool
    {
        return null === $this->clearedAt || $this->conversation->getLastMessageAt() > $this->clearedAt;
    }

    /** Ce participant voit-il ce message ? */
    public function sees(Message $message): bool
    {
        $at = $message->getSentAt();

        return $at >= $this->joinedAt
            && (null === $this->leftAt || $at <= $this->leftAt)
            && (null === $this->clearedAt || $at > $this->clearedAt);
    }

    public function markRead(\DateTimeImmutable $at): void
    {
        $this->lastReadAt = $at;
    }

    public function archive(bool $archived = true): void
    {
        $this->archived = $archived;
    }

    public function clear(\DateTimeImmutable $at): void
    {
        $this->clearedAt = $at;
        $this->lastReadAt = $at;
        $this->archived = false;
    }

    public function leave(\DateTimeImmutable $at): void
    {
        $this->leftAt = $at;
    }

    public function rejoin(\DateTimeImmutable $at): void
    {
        if (null !== $this->leftAt) {
            $this->leftAt = null;
            $this->joinedAt = $at;
        }
    }
}
