<?php

declare(strict_types=1);

namespace App\Model\Fleet;

use App\Entity\GlobalPosition;
use App\Enum\Fleet\SegmentKind;

/**
 * Segment rectiligne d'une trajectoire, dans le repère global de la galaxie.
 */
final readonly class TrajectorySegment
{
    public float $distance;

    public function __construct(
        public SegmentKind $kind,
        public GlobalPosition $from,
        public GlobalPosition $to,
    ) {
        $this->distance = $from->distanceTo($to);
    }

    /** Point situé à la fraction donnée du segment (0 : départ, 1 : arrivée) */
    public function pointAt(float $fraction): GlobalPosition
    {
        $fraction = max(0.0, min(1.0, $fraction));

        return new GlobalPosition(
            $this->from->x + ($this->to->x - $this->from->x) * $fraction,
            $this->from->y + ($this->to->y - $this->from->y) * $fraction,
        );
    }
}
