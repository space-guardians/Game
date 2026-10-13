<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\Messaging\SystemAudience;

/**
 * Livraison asynchrone d'un message système à un large public (§5.7) : les destinataires sont ajoutés à la
 * conversation par lots, puis prévenus en temps réel.
 */
final readonly class DeliverSystemMessage
{
    /**
     * @param list<int> $empireIds destinataires désignés (audience « Des joueurs »)
     */
    public function __construct(
        public int $conversationId,
        public SystemAudience $audience,
        public ?int $allianceId = null,
        public array $empireIds = [],
    ) {}
}
