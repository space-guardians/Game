<?php

declare(strict_types=1);

namespace App\Exception\Messaging;

/** Envoi ou action refusée en messagerie : destinataire inconnu, message vide ou trop long, conversation quittée… */
final class InvalidMessage extends \DomainException {}
