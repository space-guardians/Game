<?php

declare(strict_types=1);

namespace App\Exception\Fleet;

/**
 * Trop de commandes en attente au chantier spatial de la planète.
 */
final class ShipyardQueueFull extends \DomainException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(\sprintf('Le chantier spatial accepte au plus %d commandes à la fois.', $limit));
    }
}
