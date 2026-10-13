<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Message d'une conversation (§4.12.2) ; auteur nul pour un message système (#83).
 */
#[ORM\Entity]
#[ORM\Index(name: 'message_conversation_idx', fields: ['conversation', 'sentAt'])]
final class Message
{
    public const int BODY_MAX_LENGTH = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'messages')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Conversation $conversation,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private readonly ?Empire $author,
        #[ORM\Column(type: Types::TEXT)]
        private readonly string $body,
        #[ORM\Column]
        private readonly \DateTimeImmutable $sentAt,
        /** Lien vers l'écran concerné (rapport de combat, d'espionnage…) pour un message du jeu */
        #[ORM\Column(length: 255, nullable: true)]
        private readonly ?string $link = null,
    ) {}

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getAuthor(): ?Empire
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
