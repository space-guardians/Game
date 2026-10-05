<?php

declare(strict_types=1);

namespace App\Model\Universe;

/**
 * Planète libre pouvant devenir une planète mère, avec ce qu'il faut pour la choisir.
 */
final readonly class PlanetCandidate
{
    public function __construct(
        public int $planetId,
        public int $systemId,
        /** Distance du système au centre de la galaxie */
        public float $distance,
        /** Planètes déjà occupées dans le même système */
        public int $occupiedInSystem,
    ) {}
}
