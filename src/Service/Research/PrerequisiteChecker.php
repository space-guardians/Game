<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\BuildingType;
use App\Entity\Empire;
use App\Entity\Planet;
use App\Entity\Prerequisite;
use App\Entity\Technology;
use App\Repository\PlanetRepository;
use App\Repository\PrerequisiteRepository;

/**
 * Prérequis non remplis (§4.4). Technologies requises : celles de l'empire. Bâtiments requis : ceux de la planète
 * pour un bâtiment ; pour une technologie, la somme des niveaux sur toutes les planètes de l'empire, comme pour la
 * vitesse de recherche (une recherche se lance depuis n'importe quelle planète).
 */
final readonly class PrerequisiteChecker
{
    public function __construct(
        private PrerequisiteRepository $prerequisites,
        private PrerequisiteRules $rules,
        private PlanetRepository $planets,
    ) {}

    /** @return list<Prerequisite> */
    public function missing(BuildingType $type, Planet $planet): array
    {
        return $this->rules->missing($this->prerequisites->findFor($type), $this->planetBuildings($planet), $this->technologies($planet->getOwner()));
    }

    /** @return list<Prerequisite> */
    public function missingForResearch(Technology $technology, Empire $empire): array
    {
        return $this->rules->missing($this->prerequisites->findFor($technology), $this->empireBuildings($empire), $this->technologies($empire));
    }

    /**
     * Prérequis non remplis de chaque bâtiment, par code (les bâtiments débloqués n'y figurent pas) : un écran entier
     * en une requête.
     *
     * @return array<string, list<Prerequisite>>
     */
    public function missingForBuildings(Planet $planet): array
    {
        return $this->missingByTarget(BuildingType::class, $this->planetBuildings($planet), $this->technologies($planet->getOwner()));
    }

    /**
     * Prérequis non remplis de chaque technologie, par code (les technologies débloquées n'y figurent pas).
     *
     * @return array<string, list<Prerequisite>>
     */
    public function missingForTechnologies(Empire $empire): array
    {
        return $this->missingByTarget(Technology::class, $this->empireBuildings($empire), $this->technologies($empire));
    }

    /**
     * @param class-string<BuildingType|Technology> $targetClass
     * @param array<string, int>                    $buildings
     * @param array<string, int>                    $technologies
     *
     * @return array<string, list<Prerequisite>>
     */
    private function missingByTarget(string $targetClass, array $buildings, array $technologies): array
    {
        $byTarget = [];
        foreach ($this->prerequisites->findAllWithRelations() as $prerequisite) {
            $target = $prerequisite->getTarget();
            if ($target instanceof $targetClass) {
                $byTarget[$target->getCode()][] = $prerequisite;
            }
        }

        $missing = [];
        foreach ($byTarget as $code => $prerequisites) {
            $unmet = $this->rules->missing($prerequisites, $buildings, $technologies);
            if ([] !== $unmet) {
                $missing[$code] = $unmet;
            }
        }

        return $missing;
    }

    /** @return array<string, int> niveaux des bâtiments de la planète, par code */
    private function planetBuildings(Planet $planet): array
    {
        $levels = [];
        foreach ($planet->getBuildings() as $building) {
            $levels[$building->getType()->getCode()] = $building->getLevel();
        }

        return $levels;
    }

    /** @return array<string, int> somme des niveaux de chaque bâtiment sur les planètes de l'empire, par code */
    private function empireBuildings(Empire $empire): array
    {
        $levels = [];
        foreach ($this->planets->findOwnedBy($empire) as $planet) {
            foreach ($this->planetBuildings($planet) as $code => $level) {
                $levels[$code] = ($levels[$code] ?? 0) + $level;
            }
        }

        return $levels;
    }

    /** @return array<string, int> niveaux des technologies de l'empire, par code */
    private function technologies(?Empire $empire): array
    {
        $levels = [];
        foreach ($empire?->getResearches() ?? [] as $research) {
            $levels[$research->getTechnology()->getCode()] = $research->getLevel();
        }

        return $levels;
    }
}
