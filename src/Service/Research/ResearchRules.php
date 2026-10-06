<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\Technology;
use App\Model\Economy\Resources;

/**
 * Formules de la recherche (§4.4), sans accès aux données. La durée (laboratoires de l'empire) arrive avec la file
 * de recherche.
 */
final readonly class ResearchRules
{
    /** Coût du niveau visé : coût de base × facteur^(niveau − 1), comme pour les bâtiments */
    public function cost(Technology $technology, int $level): Resources
    {
        if ($level < 1) {
            throw new \InvalidArgumentException('Le premier niveau de recherche est le niveau 1.');
        }

        return $technology->getBaseCost()->times($technology->getCostFactor() ** ($level - 1));
    }
}
