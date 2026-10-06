<?php

declare(strict_types=1);

namespace App\Model\Fleet;

/**
 * Coordonnées saisies dans un ordre de flotte : « galaxie:système:position » pour une planète, « galaxie:système » pour
 * le système entier (§2.1, §4.6.2).
 */
final readonly class OrderCoordinates implements \Stringable
{
    public function __construct(
        public int $galaxy,
        public int $system,
        public ?int $position = null,
    ) {}

    public function __toString(): string
    {
        return null === $this->position
            ? \sprintf('système %d:%d', $this->galaxy, $this->system)
            : \sprintf('%d:%d:%d', $this->galaxy, $this->system, $this->position);
    }
}
