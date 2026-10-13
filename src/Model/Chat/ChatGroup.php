<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Groupe de chat (§4.12.1) : salon éphémère créé par un joueur, gardé dans Redis.
 */
final readonly class ChatGroup implements \JsonSerializable
{
    /**
     * @param array<int, string> $members empires participants, par identifiant
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $ownerId,
        public array $members,
    ) {}

    public function channel(): string
    {
        return 'group:' . $this->id;
    }

    public function has(int $empireId): bool
    {
        return isset($this->members[$empireId]);
    }

    /** @return array{id: string, channel: string, name: string, ownerId: int, members: list<array{id: int, name: string}>} */
    public function jsonSerialize(): array
    {
        $members = [];
        foreach ($this->members as $id => $name) {
            $members[] = ['id' => $id, 'name' => $name];
        }

        return ['id' => $this->id, 'channel' => $this->channel(), 'name' => $this->name, 'ownerId' => $this->ownerId, 'members' => $members];
    }
}
