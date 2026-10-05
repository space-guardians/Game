<?php

declare(strict_types=1);

namespace App\Model\Universe;

use App\Entity\Galaxy;

/**
 * Galaxie générée et enregistrée, avec son bilan.
 */
final readonly class CreatedGalaxy
{
    public function __construct(
        public Galaxy $galaxy,
        public int $systems,
        public int $planets,
        /** Distance au centre du système le plus éloigné */
        public float $radius,
    ) {}
}
