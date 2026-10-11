<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Fleet;
use App\Model\Combat\SideMotion;
use App\Repository\FleetMovementRepository;
use App\Service\Fleet\TrajectoryPlanner;

/**
 * Mouvement d'un camp au moment d'un combat (§4.7) : à l'arrêt si l'une de ses flottes tient sa position, sinon son
 * vecteur d'approche. Le cap d'une flotte en vol est celui du dernier segment de sa trajectoire, celui qui l'amène
 * au point de rencontre.
 */
final readonly class CombatMotions
{
    public function __construct(
        private FleetMovementRepository $movements,
        private TrajectoryPlanner $planner,
    ) {}

    /** @param list<Fleet> $fleets */
    public function of(array $fleets): SideMotion
    {
        $headings = [];
        foreach ($fleets as $fleet) {
            $heading = $this->heading($fleet);
            if (null !== $heading) {
                $headings[(int) $fleet->getId()] = $heading;
            }
        }

        return SideMotion::ofFleets($fleets, $headings);
    }

    /** @return array{float, float}|null cap (dx, dy) d'une flotte en vol ; null à l'arrêt */
    public function heading(Fleet $fleet): ?array
    {
        $movement = $this->movements->findActiveFor($fleet);
        if (null === $movement) {
            return null;
        }
        $segments = $this->planner->plan($movement->getOrigin()->toPosition(), $movement->getOrder()->getDestination()->toPosition())->segments;
        $last = end($segments);
        if (false === $last) {
            return null;
        }

        return [$last->to->x - $last->from->x, $last->to->y - $last->from->y];
    }
}
