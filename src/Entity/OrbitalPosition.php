<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Position d'une planète, locale à son système : orbite concentrique autour de l'étoile, puis angle sur cette orbite.
 *
 * Le numéro d'orbite est la « Position » de l'adresse logique (Galaxie : Système : Position).
 *
 * @see §2.2 du cahier des charges
 */
#[ORM\Embeddable]
final readonly class OrbitalPosition
{
    public const int MAX_ORBIT = 15;

    /** Angle en radians, normalisé dans [0 ; 2π[ */
    #[ORM\Column]
    public float $angle;

    public function __construct(
        #[ORM\Column]
        public int $orbit,
        #[ORM\Column]
        public float $radius,
        float $angle,
    ) {
        if ($orbit < 1 || $orbit > self::MAX_ORBIT) {
            throw new \InvalidArgumentException(\sprintf('L\'orbite doit être comprise entre 1 et %d.', self::MAX_ORBIT));
        }
        if (!is_finite($radius) || $radius <= 0) {
            throw new \InvalidArgumentException('Le rayon de l\'orbite doit être strictement positif.');
        }
        if (!is_finite($angle)) {
            throw new \InvalidArgumentException('L\'angle doit être fini.');
        }

        $angle = fmod($angle, 2 * M_PI);
        $this->angle = $angle < 0 ? $angle + 2 * M_PI : $angle;
    }

    /**
     * Coordonnées cartésiennes de la planète relativement à son étoile.
     *
     * @return array{x: float, y: float}
     */
    public function toLocalCartesian(): array
    {
        return [
            'x' => $this->radius * cos($this->angle),
            'y' => $this->radius * sin($this->angle),
        ];
    }
}
