<?php

declare(strict_types=1);

namespace App\Exception\Chat;

use App\Entity\Empire;

/**
 * Message privé vers un joueur hors ligne (§4.12.1) : le chat ne joint que les joueurs connectés ; on passe alors par
 * la messagerie.
 */
final class ChatRecipientOffline extends \DomainException
{
    public function __construct(
        public readonly Empire $recipient,
    ) {
        parent::__construct(\sprintf('%s est hors ligne : écrivez-lui plutôt dans la messagerie.', $recipient->getName()));
    }
}
