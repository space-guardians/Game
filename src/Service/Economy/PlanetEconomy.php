<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\BuildingType;
use App\Entity\Planet;
use App\Model\Economy\EconomySettings;
use App\Model\Economy\PlanetOutput;
use App\Model\Economy\Resources;
use App\Repository\BuildingTypeRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Bilan économique d'une planète (§4.2) à partir des niveaux de ses bâtiments : production, énergie, stockage.
 * Une planète sans propriétaire ne produit rien.
 */
final class PlanetEconomy implements ResetInterface
{
    /** @var list<BuildingType>|null types chargés une fois par requête ou par message */
    private ?array $types = null;

    public function __construct(
        private readonly EconomySettings $settings,
        private readonly BuildingTypeRepository $buildingTypes,
        private readonly ProductionCalculator $calculator,
    ) {}

    public function output(Planet $planet): PlanetOutput
    {
        $buildings = array_map(
            static fn(BuildingType $type): array => ['type' => $type, 'level' => $planet->buildingLevel($type)],
            $this->types ??= $this->buildingTypes->findAllOrdered(),
        );

        return null === $planet->getOwner()
            ? $this->calculator->idle($buildings)
            : $this->calculator->compute($buildings, $planet->getTemperature(), $planet->getPosition()->orbit, $this->settings);
    }

    public function startingResources(): Resources
    {
        return $this->settings->startingResources;
    }

    public function reset(): void
    {
        $this->types = null;
    }
}
