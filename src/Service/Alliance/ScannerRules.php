<?php

declare(strict_types=1);

namespace App\Service\Alliance;

use App\Entity\GlobalPosition;
use App\Model\Fleet\Trajectory;
use App\Model\Fleet\TrajectorySegment;

/**
 * Règles du scanner d'alliance (§4.10), pures : portée selon le niveau, plus courte distance d'un trajet à la planète,
 * avancement d'une flotte en vol. Valeurs provisoires (§7 : portée du scanner).
 */
final readonly class ScannerRules
{
    /** Portée gagnée par niveau, dans le repère global (deux systèmes sont distants d'au moins 200) */
    public const float RANGE_PER_LEVEL = 500.0;

    public function range(int $level): float
    {
        return max(0, $level) * self::RANGE_PER_LEVEL;
    }

    /** Plus courte distance entre la planète et le trajet entier */
    public function closestApproach(Trajectory $trajectory, GlobalPosition $point): float
    {
        if ([] === $trajectory->segments) {
            return $trajectory->to->global()->distanceTo($point);
        }

        return min(array_map(static fn(TrajectorySegment $segment): float => self::toSegment($segment->from, $segment->to, $point), $trajectory->segments));
    }

    /**
     * Fraction du trajet parcourue à l'instant donné ; une flotte qui tombera en panne n'en parcourt que $reach.
     */
    public function travelled(\DateTimeImmutable $departedAt, \DateTimeImmutable $arrivesAt, float $reach, \DateTimeImmutable $at): float
    {
        $duration = $arrivesAt->getTimestamp() - $departedAt->getTimestamp();
        $progress = $duration <= 0 ? 1.0 : ($at->getTimestamp() - $departedAt->getTimestamp()) / $duration;

        return max(0.0, min(1.0, $progress)) * max(0.0, min(1.0, $reach));
    }

    private static function toSegment(GlobalPosition $from, GlobalPosition $to, GlobalPosition $point): float
    {
        $dx = $to->x - $from->x;
        $dy = $to->y - $from->y;
        $length = $dx * $dx + $dy * $dy;
        $t = $length <= 0.0 ? 0.0 : max(0.0, min(1.0, (($point->x - $from->x) * $dx + ($point->y - $from->y) * $dy) / $length));

        return hypot($from->x + $t * $dx - $point->x, $from->y + $t * $dy - $point->y);
    }
}
