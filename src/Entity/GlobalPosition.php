<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Position d'un système stellaire dans sa galaxie, en coordonnées globales depuis le centre (0 ; 0).
 *
 * @see §2.2 du cahier des charges
 */
#[ORM\Embeddable]
final readonly class GlobalPosition
{
    public function __construct(
        #[ORM\Column]
        public float $x,
        #[ORM\Column]
        public float $y,
    ) {
        if (!is_finite($x) || !is_finite($y)) {
            throw new \InvalidArgumentException('Les coordonnées globales doivent être finies.');
        }
    }

    public function distanceTo(self $other): float
    {
        return hypot($other->x - $this->x, $other->y - $this->y);
    }

    public function distanceFromCenter(): float
    {
        return hypot($this->x, $this->y);
    }
}
