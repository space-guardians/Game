<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\BuildingType;
use App\Entity\Planet;
use App\Entity\Prerequisite;
use App\Entity\Technology;
use App\Repository\PrerequisiteRepository;

/**
 * Prérequis non remplis d'un bâtiment ou d'une technologie (§4.4), pour une planète : ses bâtiments, et les
 * recherches de l'empire qui la possède.
 */
final readonly class PrerequisiteChecker
{
    public function __construct(
        private PrerequisiteRepository $prerequisites,
        private PrerequisiteRules $rules,
    ) {}

    /** @return list<Prerequisite> */
    public function missing(BuildingType|Technology $target, Planet $planet): array
    {
        return $this->rules->missing($this->prerequisites->findFor($target), ...$this->levels($planet));
    }

    /**
     * Prérequis non remplis de chaque bâtiment, par code (les bâtiments débloqués n'y figurent pas) : un écran entier
     * en une requête.
     *
     * @return array<string, list<Prerequisite>>
     */
    public function missingForBuildings(Planet $planet): array
    {
        $byTarget = [];
        foreach ($this->prerequisites->findAllWithRelations() as $prerequisite) {
            $target = $prerequisite->getTarget();
            if ($target instanceof BuildingType) {
                $byTarget[$target->getCode()][] = $prerequisite;
            }
        }

        $levels = $this->levels($planet);
        $missing = [];
        foreach ($byTarget as $code => $prerequisites) {
            $unmet = $this->rules->missing($prerequisites, ...$levels);
            if ([] !== $unmet) {
                $missing[$code] = $unmet;
            }
        }

        return $missing;
    }

    /** @return array{0: array<string, int>, 1: array<string, int>} niveaux des bâtiments de la planète et des technologies de l'empire */
    private function levels(Planet $planet): array
    {
        $buildings = [];
        foreach ($planet->getBuildings() as $building) {
            $buildings[$building->getType()->getCode()] = $building->getLevel();
        }
        $technologies = [];
        foreach ($planet->getOwner()?->getResearches() ?? [] as $research) {
            $technologies[$research->getTechnology()->getCode()] = $research->getLevel();
        }

        return [$buildings, $technologies];
    }
}
