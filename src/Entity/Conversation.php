<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Conversation de la messagerie (§4.12.2, §5.7) : à deux (joueur ↔ joueur) ou de groupe (plusieurs destinataires,
 * participants ajoutés ensuite, départ possible). Chaque participant a son propre état (lu, archivé, supprimé de
 * son côté). Non auditée : contenu des joueurs, modéré à part (#91).
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
final class Conversation
{
    public const int SUBJECT_MAX_LENGTH = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $lastMessageAt;

    /** @var Collection<int, ConversationParticipant> */
    #[ORM\OneToMany(targetEntity: ConversationParticipant::class, mappedBy: 'conversation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $participants;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'conversation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sentAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $messages;

    public function __construct(
        /** Conversation de groupe (ajout de participants, départ) plutôt qu'à deux */
        #[ORM\Column(name: 'is_group')]
        private readonly bool $group,
        #[ORM\Column(length: self::SUBJECT_MAX_LENGTH, nullable: true)]
        private readonly ?string $subject,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->lastMessageAt = $createdAt;
        $this->participants = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isGroup(): bool
    {
        return $this->group;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastMessageAt(): \DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    /** @return Collection<int, ConversationParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    /** @return list<ConversationParticipant> participants qui n'ont pas quitté la conversation */
    public function activeParticipants(): array
    {
        return array_values(array_filter($this->participants->toArray(), static fn(ConversationParticipant $participant): bool => $participant->isActive()));
    }

    public function participantOf(Empire $empire): ?ConversationParticipant
    {
        return $this->participants->findFirst(static fn(int $key, ConversationParticipant $participant): bool => $participant->getEmpire() === $empire);
    }

    /** Ajoute (ou réintègre) un participant, à partir de maintenant */
    public function add(Empire $empire, \DateTimeImmutable $at): ConversationParticipant
    {
        $participant = $this->participantOf($empire);
        if (null === $participant) {
            $participant = new ConversationParticipant($this, $empire, $at);
            $this->participants->add($participant);
        } else {
            $participant->rejoin($at);
        }

        return $participant;
    }

    /** @return Collection<int, Message> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function post(?Empire $author, string $body, \DateTimeImmutable $at): Message
    {
        $message = new Message($this, $author, $body, $at);
        $this->messages->add($message);
        $this->lastMessageAt = $at;

        return $message;
    }

    /** Titre affiché à un participant : l'objet, sinon les noms des autres participants */
    public function titleFor(Empire $empire): string
    {
        if (null !== $this->subject && '' !== $this->subject) {
            return $this->subject;
        }
        $others = [];
        foreach ($this->participants as $participant) {
            if ($participant->getEmpire() !== $empire) {
                $others[] = $participant->getEmpire()->getName();
            }
        }

        return [] === $others ? 'Conversation' : implode(', ', $others);
    }
}
