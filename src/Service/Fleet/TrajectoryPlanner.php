<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\GlobalPosition;
use App\Enum\Fleet\SegmentKind;
use App\Model\Fleet\SpacePosition;
use App\Model\Fleet\Trajectory;
use App\Model\Fleet\TrajectorySegment;

/**
 * Découpe un trajet en segments (§4.6.1), sans accès aux données. Dans un même système : un seul trajet local. Sinon :
 * sortie du système d'origine jusqu'à sa lisière, du côté de la destination ; transit interstellaire jusqu'à la lisière
 * du système cible, du côté de l'origine (ou jusqu'au point visé hors système) ; approche jusqu'au point visé.
 * Une flotte au niveau d'un système (système entier) part de sa lisière, ou s'y arrête.
 */
final readonly class TrajectoryPlanner
{
    /**
     * Rayon de la lisière d'un système : au-delà de la dernière orbite possible (PlanetGenerator::maxRadius(),
     * vérifié par un test), en deçà de la moitié de l'écart minimal entre deux systèmes.
     */
    public const float SYSTEM_RADIUS = 96.0;

    public function plan(SpacePosition $from, SpacePosition $to): Trajectory
    {
        if ($from->galaxyId !== $to->galaxyId) {
            throw new \InvalidArgumentException('Un trajet reste dans une même galaxie.');
        }

        if ($from->isSameSystem($to)) {
            return new Trajectory($from, $to, $this->segments([
                [SegmentKind::Local, $this->sameSystemPoint($from, $to), $this->sameSystemPoint($to, $from)],
            ]));
        }

        // Points d'entrée et de sortie des systèmes, sur la droite qui relie les deux systèmes (ou points)
        $departure = $from->isInSystem() ? $this->edge($from->anchor, $to->anchor) : $from->anchor;
        $arrival = $to->isInSystem() ? $this->edge($to->anchor, $from->anchor) : $to->anchor;

        return new Trajectory($from, $to, $this->segments([
            [SegmentKind::Exit, $from->global(), $from->hasLocalPoint() ? $departure : null],
            [SegmentKind::Interstellar, $departure, $arrival],
            [SegmentKind::Approach, $arrival, $to->hasLocalPoint() ? $to->global() : null],
        ]));
    }

    /**
     * Segments non vides (un segment sans arrivée ou de longueur nulle disparaît).
     *
     * @param list<array{0: SegmentKind, 1: GlobalPosition, 2: ?GlobalPosition}> $candidates
     *
     * @return list<TrajectorySegment>
     */
    private function segments(array $candidates): array
    {
        $segments = [];
        foreach ($candidates as [$kind, $start, $end]) {
            if (null !== $end && $start->distanceTo($end) > 0.0) {
                $segments[] = new TrajectorySegment($kind, $start, $end);
            }
        }

        return $segments;
    }

    /** Point de la lisière du système centré en $center, dans la direction de $toward */
    private function edge(GlobalPosition $center, GlobalPosition $toward): GlobalPosition
    {
        $distance = $center->distanceTo($toward);
        if ($distance <= 0.0) {
            return new GlobalPosition($center->x + self::SYSTEM_RADIUS, $center->y);
        }

        return new GlobalPosition(
            $center->x + ($toward->x - $center->x) / $distance * self::SYSTEM_RADIUS,
            $center->y + ($toward->y - $center->y) / $distance * self::SYSTEM_RADIUS,
        );
    }

    /** Dans un même système, une position « système entier » se trouve sur la lisière, du côté de l'autre point */
    private function sameSystemPoint(SpacePosition $position, SpacePosition $other): GlobalPosition
    {
        if ($position->hasLocalPoint()) {
            return $position->global();
        }

        return $other->hasLocalPoint() ? $this->edge($position->anchor, $other->global()) : $position->anchor;
    }
}
