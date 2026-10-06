<?php

declare(strict_types=1);

namespace App\Exception\Economy;

/**
 * Rien à annuler : aucune construction en cours, ou elle est déjà arrivée à son terme (sa fin est en cours de
 * résolution et le bâtiment va changer de niveau).
 */
final class NoCancellableConstruction extends \DomainException
{
    public static function none(): self
    {
        return new self('Aucune construction en cours sur cette planète.');
    }

    public static function finished(): self
    {
        return new self('La construction est terminée : elle ne peut plus être annulée.');
    }
}
