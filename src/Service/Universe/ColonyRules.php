<?php

declare(strict_types=1);

namespace App\Service\Universe;

/**
 * Nombre de colonies d'un empire (§4.1) : limité par le niveau de la technologie « Astrophysique », comme dans les
 * OGame-like — une colonie par tranche de deux niveaux, arrondie au supérieur (niveaux 1 et 2 : une ; 3 et 4 : deux…).
 * La planète mère ne compte pas.
 */
final readonly class ColonyRules
{
    /** Code de la technologie qui ouvre les emplacements de colonisation */
    public const string ASTROPHYSICS = 'astrophysics';

    public function maxColonies(int $astrophysicsLevel): int
    {
        return intdiv(max(0, $astrophysicsLevel) + 1, 2);
    }

    /** Niveau d'astrophysique nécessaire pour la colonie suivante */
    public function levelFor(int $colonies): int
    {
        return max(1, 2 * $colonies + 1);
    }
}
