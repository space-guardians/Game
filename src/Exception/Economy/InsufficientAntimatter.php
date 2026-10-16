<?php

declare(strict_types=1);

namespace App\Exception\Economy;

/**
 * Pas assez d'antimatière sur la planète pour payer le coût (§4.14).
 */
final class InsufficientAntimatter extends \DomainException
{
    public function __construct(public readonly float $missing)
    {
        parent::__construct(\sprintf('Antimatière insuffisante : il en manque %s.', number_format(ceil($missing), 0, ',', ' ')));
    }
}
