<?php

declare(strict_types=1);

namespace App\Model\Admin;

use App\Entity\BuildingQueueItem;
use App\Entity\Planet;
use App\Entity\PlanetBuilding;
use App\Model\Economy\ResourceSnapshot;

/**
 * Planète d'un joueur, telle que la montre sa fiche dans le panneau : ressources à l'instant, bâtiments,
 * construction en cours.
 */
final readonly class PlayerPlanet
{
    /**
     * @param list<PlanetBuilding> $buildings bâtiments construits (niveau > 0)
     */
    public function __construct(
        public Planet $planet,
        public bool $home,
        public ResourceSnapshot $resources,
        public array $buildings,
        public ?BuildingQueueItem $construction,
    ) {}
}
