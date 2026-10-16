<?php

declare(strict_types=1);

namespace App\Exception\Fleet;

use App\Entity\ShipType;

/**
 * Type de vaisseau réservé (§4.14) : son plan, gagné sur un « boss », manque à l'empire.
 */
final class MissingBlueprint extends \DomainException
{
    public function __construct(public readonly ShipType $type)
    {
        parent::__construct(\sprintf('%s se construit sur plan : remportez-le en battant une civilisation « boss ».', $type->getName()));
    }
}
