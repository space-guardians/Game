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
        private FuelRules $fuel,
    ) {}

    /** Deutérium que la flotte brûle pour ce trajet (§4.6.3) */
    public function fuelFor(Fleet $fleet, TravelPlan $plan): float
    {
        $ships = [];
        foreach ($fleet->getShips() as $fleetShips) {
            $type = $fleetShips->getType();
            $ships[] = ['consumption' => $type->getFuelConsumption(), 'quantity' => $fleetShips->getQuantity(), 'drive' => $type->getDrive()?->getCode()];
        }

        return $this->fuel->consumption($ships, $plan->trajectory->distance, $plan->speedPercent);
    }

    /** Position actuelle d'une flotte stationnée (ou point de départ de son déplacement en cours) */
    public function positionOf(Fleet $fleet): SpacePosition
    {
        return $fleet->getLocation()->toPosition();
    }

    public function plan(Fleet $fleet, SpacePosition $destination, int $speedPercent = 100, ?SpacePosition $from = null): TravelPlan
    {
        $trajectory = $this->planner->plan($from ?? $this->positionOf($fleet), $destination);
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
