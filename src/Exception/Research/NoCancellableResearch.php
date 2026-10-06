<?php

declare(strict_types=1);

namespace App\Exception\Research;

/**
 * Rien à annuler : aucune recherche en cours dans l'empire, ou elle est déjà arrivée à son terme (sa fin est en
 * cours de résolution et la technologie va changer de niveau).
 */
final class NoCancellableResearch extends \DomainException
{
    public static function none(): self
    {
        return new self('Aucune recherche en cours dans l’empire.');
    }

    public static function finished(): self
    {
        return new self('La recherche est terminée : elle ne peut plus être annulée.');
    }
}
