<?php

declare(strict_types=1);

namespace App\Service\Universe;

use App\Entity\Empire;
use App\Model\Universe\ColonySlots;
use App\Repository\PlanetRepository;

/**
 * Emplacements de colonisation d'un empire (§4.1) : ses colonies (planètes hors planète mère) face au maximum permis
 * par son niveau d'astrophysique (ColonyRules).
 */
final readonly class ColonyLimit
{
    public function __construct(
        private PlanetRepository $planets,
        private ColonyRules $rules,
    ) {}

    public function slots(Empire $empire): ColonySlots
    {
        $level = 0;
        foreach ($empire->getResearches() as $research) {
            if (ColonyRules::ASTROPHYSICS === $research->getTechnology()->getCode()) {
                $level = $research->getLevel();
            }
        }
        $colonies = max(0, $this->planets->countOwnedBy($empire) - 1);

        return new ColonySlots($colonies, $this->rules->maxColonies($level), $level, $this->rules->levelFor($colonies));
    }
}
