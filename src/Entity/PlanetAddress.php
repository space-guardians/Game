<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Adresse logique d'une planète, utilisée pour l'affichage, les liens directs et le classement.
 *
 * @see §2.1 du cahier des charges
 */
final readonly class PlanetAddress implements \Stringable
{
    public function __construct(
        public int $galaxy,
        public int $system,
        public int $position,
    ) {}

    /** Forme compacte, ex. « 1:342:7 » */
    public function __toString(): string
    {
        return \sprintf('%d:%d:%d', $this->galaxy, $this->system, $this->position);
    }
}
