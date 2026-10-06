<?php

declare(strict_types=1);

namespace App\Exception\Fleet;

/**
 * Formation refusée : la répartition ne place pas exactement les vaisseaux de la flotte, ou la flotte n'est pas
 * stationnée.
 */
final class InvalidFormation extends \DomainException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(implode(' ', $violations));
    }
}
