<?php

declare(strict_types=1);

namespace App\Exception\Combat;

/** Matrice des classes refusée : multiplicateurs hors bornes ou illisibles ; rien n'est enregistré */
final class InvalidMatchupMatrix extends \DomainException
{
    /** @param list<string> $violations */
    public function __construct(
        public readonly array $violations,
    ) {
        parent::__construct(implode(' ', $violations));
    }
}
