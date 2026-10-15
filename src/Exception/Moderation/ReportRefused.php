<?php

declare(strict_types=1);

namespace App\Exception\Moderation;

/** Signalement refusé (message introuvable, son propre message, motif vide…) ; message montré au joueur */
final class ReportRefused extends \DomainException {}
