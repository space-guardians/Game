<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Message de chat en ligne (§4.12.1) : jamais enregistré en base, seulement diffusé et gardé un temps dans le tampon
 * du canal.
 */
final readonly class ChatMessage implements \JsonSerializable
{
    public function __construct(
        public string $id,
        /** Canal : « public », puis alliance, privé, groupe */
        public string $channel,
        public int $authorId,
        public string $author,
        public string $body,
        public \DateTimeImmutable $sentAt,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['channel'] ?? ''),
            (int) ($data['authorId'] ?? 0),
            (string) ($data['author'] ?? ''),
            (string) ($data['body'] ?? ''),
            new \DateTimeImmutable((string) ($data['sentAt'] ?? 'now')),
        );
    }

    /** @return array{type: string, id: string, channel: string, authorId: int, author: string, body: string, sentAt: string} */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'message',
            'id' => $this->id,
            'channel' => $this->channel,
            'authorId' => $this->authorId,
            'author' => $this->author,
            'body' => $this->body,
            'sentAt' => $this->sentAt->format(\DATE_ATOM),
        ];
    }
}
