<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Génère en arrière-plan la galaxie décrite par une demande GalaxyGeneration (§5.6.2 : traitement long).
 */
final readonly class GenerateGalaxy
{
    public function __construct(
        public int $generationId,
    ) {}
}
