<?php

declare(strict_types=1);

namespace App\Exception\Universe;

/**
 * Une galaxie porte déjà ce numéro : l'univers n'est pas modifié.
 */
final class GalaxyNumberTaken extends \InvalidArgumentException
{
    public function __construct(int $number)
    {
        parent::__construct(\sprintf('La galaxie n°%d existe déjà.', $number));
    }
}
