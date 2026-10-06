<?php

declare(strict_types=1);

namespace App\Model\Fleet;

use App\Entity\GlobalPosition;

/**
 * Trajet calculé pour une flotte : trajectoire, vitesse retenue et durée. Permet de situer la flotte à tout instant.
 */
final readonly class TravelPlan
{
    public function __construct(
        public Trajectory $trajectory,
        /** Vitesse du vaisseau le plus lent, propulsion comprise */
        public float $speed,
        public int $speedPercent,
        public int $durationSeconds,
    ) {}

    public function arrivalFrom(\DateTimeImmutable $departure): \DateTimeImmutable
    {
        return $departure->modify(\sprintf('+%d seconds', $this->durationSeconds));
    }

    /**
     * Position de la flotte à l'instant donné, partie à $departure.
     *
     * @return array{position: GlobalPosition, segment: ?TrajectorySegment, progress: float}
     */
    public function positionAt(\DateTimeImmutable $departure, \DateTimeImmutable $at): array
    {
        $elapsed = (float) $at->format('U.u') - (float) $departure->format('U.u');
        $progress = max(0.0, min(1.0, $elapsed / max(1, $this->durationSeconds)));

        return [...$this->trajectory->pointAt($progress), 'progress' => $progress];
    }
}
