<?php

declare(strict_types=1);

namespace App\Model\Exploration;

use App\Model\Economy\Resources;

/**
 * Ce qu'une flotte apporte à une exploration (§4.6.4) : technologies de son empire, vaisseaux envoyés, cargaison.
 * Sert à vérifier les conditions de déclenchement d'une quête.
 */
final readonly class ExplorationContext
{
    /**
     * @param array<string, int> $technologyLevels niveaux de l'empire, par code de technologie
     * @param array<string, int> $ships            vaisseaux de la flotte, par code de type
     */
    public function __construct(
        public array $technologyLevels,
        public array $ships,
        public Resources $cargo,
        public int $cargoCapacity,
    ) {}
}
