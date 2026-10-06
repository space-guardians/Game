<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\Prerequisite;

/**
 * Prérequis croisés bâtiments / technologies (§4.4), sans accès aux données : à partir des niveaux connus, ce qui
 * manque encore pour débloquer une cible.
 */
final readonly class PrerequisiteRules
{
    /**
     * Prérequis non remplis.
     *
     * @param list<Prerequisite> $prerequisites    prérequis d'une même cible
     * @param array<string, int> $buildingLevels   niveaux des bâtiments de la planète, par code
     * @param array<string, int> $technologyLevels niveaux des technologies de l'empire, par code
     *
     * @return list<Prerequisite>
     */
    public function missing(array $prerequisites, array $buildingLevels, array $technologyLevels): array
    {
        return array_values(array_filter($prerequisites, static function (Prerequisite $prerequisite) use ($buildingLevels, $technologyLevels): bool {
            $levels = $prerequisite->requiresBuilding() ? $buildingLevels : $technologyLevels;

            return ($levels[$prerequisite->getRequiredCode()] ?? 0) < $prerequisite->getLevel();
        }));
    }
}
