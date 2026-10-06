<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Empire;
use App\Entity\Planet;
use App\Entity\PlanetBuilding;
use App\Enum\Admin\AuditOrigin;
use App\Model\Admin\PlayerFile;
use App\Model\Admin\PlayerPlanet;
use App\Repository\BuildingQueueItemRepository;
use App\Repository\PlanetRepository;
use App\Repository\ScheduledEventRepository;
use App\Service\Economy\PlanetResources;

/**
 * Fiche d'un joueur pour le panneau d'administration (§5.6.1), en lecture seule : les ressources sont calculées
 * à l'instant sans être enregistrées.
 */
final readonly class PlayerFiles
{
    public const int ACTIVITY_LIMIT = 30;

    public function __construct(
        private PlanetRepository $planets,
        private PlanetResources $resources,
        private BuildingQueueItemRepository $buildingQueue,
        private ScheduledEventRepository $events,
        private EntityHistory $history,
    ) {}

    public function of(Empire $empire): PlayerFile
    {
        $planets = $this->planets->findOwnedBy($empire);
        $userId = $empire->getUser()->getId();

        return new PlayerFile(
            empire: $empire,
            planets: array_map(fn(Planet $planet): PlayerPlanet => $this->planet($empire, $planet), $planets),
            actions: null === $userId ? [] : $this->history->byAuthor($userId, AuditOrigin::Player->value, self::ACTIVITY_LIMIT),
            events: $this->events->findLatestForPlanets($planets, self::ACTIVITY_LIMIT),
        );
    }

    private function planet(Empire $empire, Planet $planet): PlayerPlanet
    {
        $buildings = array_values(array_filter(
            $planet->getBuildings()->toArray(),
            static fn(PlanetBuilding $building): bool => $building->getLevel() > 0,
        ));
        usort($buildings, static fn(PlanetBuilding $a, PlanetBuilding $b): int => strcoll($a->getType()->getName(), $b->getType()->getName()));

        return new PlayerPlanet(
            planet: $planet,
            home: $empire->isHomePlanet($planet),
            resources: $this->resources->snapshot($planet),
            buildings: $buildings,
            construction: $this->buildingQueue->findActiveFor($planet),
        );
    }
}
