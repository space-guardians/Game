<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\Planet;
use App\Model\Economy\EconomySettings;
use App\Model\Economy\Resources;

/**
 * Production horaire et capacité de stockage d'une planète (§4.2). Pour l'instant les valeurs de base ; les mines,
 * les dépôts et l'énergie s'y ajouteront (#20). Une planète sans propriétaire ne produit rien.
 */
final readonly class PlanetEconomy
{
    public function __construct(
        private EconomySettings $settings,
    ) {}

    public function hourlyProduction(Planet $planet): Resources
    {
        if (null === $planet->getOwner()) {
            return Resources::zero();
        }

        return $this->settings->baseHourlyProduction->times($this->settings->universeSpeed);
    }

    public function capacity(Planet $planet): Resources
    {
        return $this->settings->baseCapacity;
    }

    public function startingResources(): Resources
    {
        return $this->settings->startingResources;
    }
}
