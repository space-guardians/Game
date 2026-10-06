<?php

declare(strict_types=1);

namespace App\Service\Universe;

use App\Entity\Planet;
use App\Enum\Account\StartingOrientation;
use App\Exception\Universe\NoFreePlanet;
use App\Repository\PlanetRepository;

/**
 * Trouve la planète mère d'un nouvel empire selon son orientation (§2.4). L'appelant attribue la planète et
 * l'enregistre sous un verrou, pour que deux inscriptions simultanées ne reçoivent pas la même.
 */
final readonly class HomePlanetAllocator
{
    public function __construct(
        private PlanetRepository $planets,
        private StartingPlanetSelector $selector,
    ) {}

    /**
     * @throws NoFreePlanet
     */
    public function allocate(StartingOrientation $orientation): Planet
    {
        $candidate = $this->selector->select($this->planets->startingCandidates(), $orientation) ?? throw new NoFreePlanet();
        $planet = $this->planets->find($candidate->planetId);
        \assert($planet instanceof Planet);

        return $planet;
    }
}
