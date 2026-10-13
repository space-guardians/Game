<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Discussion privée ouverte dans le chat (§4.12.1) : canal commun et interlocuteur, pour l'onglet du widget.
 */
final readonly class PrivateChat implements \JsonSerializable
{
    public function __construct(
        public string $channel,
        public int $peerId,
        public string $peer,
    ) {}

    /** @return array{channel: string, peerId: int, peer: string} */
    public function jsonSerialize(): array
    {
        return ['channel' => $this->channel, 'peerId' => $this->peerId, 'peer' => $this->peer];
    }
}
