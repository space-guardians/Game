<?php

declare(strict_types=1);

namespace App\Model\Fleet;

use App\Entity\GlobalPosition;

/**
 * Trajectoire d'une flotte : jusqu'à trois segments parcourus à vitesse constante (§4.6.1).
 */
final readonly class Trajectory
{
    public float $distance;

    /**
     * @param list<TrajectorySegment> $segments dans l'ordre de parcours ; vide si départ et arrivée se confondent
     */
    public function __construct(
        public SpacePosition $from,
        public SpacePosition $to,
        public array $segments,
    ) {
        $this->distance = array_sum(array_map(static fn(TrajectorySegment $segment): float => $segment->distance, $segments));
    }

    /**
     * Position après avoir parcouru la fraction donnée de la distance totale, et segment en cours.
     *
     * @return array{position: GlobalPosition, segment: ?TrajectorySegment}
     */
    public function pointAt(float $fraction): array
    {
        $fraction = max(0.0, min(1.0, $fraction));
        if ([] === $this->segments || $this->distance <= 0.0) {
            return ['position' => $this->to->global(), 'segment' => null];
        }

        $remaining = $fraction * $this->distance;
        foreach ($this->segments as $segment) {
            if ($remaining <= $segment->distance) {
                return ['position' => $segment->pointAt($segment->distance > 0 ? $remaining / $segment->distance : 1.0), 'segment' => $segment];
            }
            $remaining -= $segment->distance;
        }
        $last = $this->segments[array_key_last($this->segments)];

        return ['position' => $last->to, 'segment' => $last];
    }
}
