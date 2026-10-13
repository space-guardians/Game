<?php

declare(strict_types=1);

namespace App\Exception\Chat;

/**
 * Message de chat refusé (vide, trop long, envoyé trop vite, canal interdit) ; le message est montré au joueur.
 */
final class ChatRefused extends \DomainException {}
