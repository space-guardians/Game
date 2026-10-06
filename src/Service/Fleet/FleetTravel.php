<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Fleet;
use App\Model\Economy\EconomySettings;
use App\Model\Fleet\SpacePosition;
use App\Model\Fleet\TravelPlan;

/**
 * Trajet d'une flotte vers une position (§4.6.1) : trajectoire découpée en segments, vitesse du vaisseau le plus lent
 * compte tenu des technologies de propulsion de son empire, durée selon le pourcentage de vitesse choisi.
 */
final readonly class FleetTravel
{
    public function __construct(
        private TrajectoryPlanner $planner,
        private TravelRules $rules,
        private EconomySettings $settings,
    ) {}

    /** Position actuelle d'une flotte stationnée */
    public function positionOf(Fleet $fleet): SpacePosition
    {
        return SpacePosition::planet($fleet->getPlanet());
    }

    public function plan(Fleet $fleet, SpacePosition $destination, int $speedPercent = 100): TravelPlan
    {
        $trajectory = $this->planner->plan($this->positionOf($fleet), $destination);
        $speed = $this->speed($fleet);

        return new TravelPlan(
            $trajectory,
            $speed,
            $speedPercent,
            $this->rules->durationSeconds($trajectory->distance, $speed, $speedPercent, $this->settings->universeSpeed),
        );
    }

    /** Vitesse de la flotte : celle de son vaisseau le plus lent, propulsion de l'empire comprise */
    public function speed(Fleet $fleet): float
    {
        $empire = $fleet->getEmpire();
        $speed = null;
        foreach ($fleet->getShips() as $ships) {
            $type = $ships->getType();
            $drive = $type->getDrive();
            $shipSpeed = $this->rules->shipSpeed($type->getSpeed(), $drive?->getCode(), null === $drive ? 0 : $empire->researchLevel($drive));
            $speed = null === $speed ? $shipSpeed : min($speed, $shipSpeed);
        }

        return $speed ?? 0.0;
    }
}
