<?php

declare(strict_types=1);

namespace App\Exception\Universe;

/**
 * Aucune planète libre pour accueillir un nouvel empire : il faut générer une galaxie.
 */
final class NoFreePlanet extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Aucune planète libre pour une planète mère : générez une nouvelle galaxie.');
    }
}
