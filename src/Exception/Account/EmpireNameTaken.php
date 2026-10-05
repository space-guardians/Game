<?php

declare(strict_types=1);

namespace App\Exception\Account;

/**
 * Un empire porte déjà ce nom (sans tenir compte de la casse).
 */
final class EmpireNameTaken extends \RuntimeException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct(\sprintf('Un empire porte déjà le nom « %s ».', $name));
    }
}
