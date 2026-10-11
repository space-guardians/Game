<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Enum\Combat\AttackAngle;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationRow;
use App\Model\Combat\SideMotion;

/**
 * Angle d'attaque et ordre d'engagement des lignes de formation de chaque camp (§4.6.2, §4.7), à passer au moteur de
 * combat :
 * - une flotte stationnée ne peut jamais être prise par surprise : elle fait toujours face à l'attaquant, qui engage
 *   sa ligne avant en premier, à une planète, un système ou toute autre position ; il n'existe pas de bonus
 *   « attaque par l'arrière » contre une cible à l'arrêt ;
 * - un camp qui fonce sur une cible à l'arrêt lui présente aussi sa ligne avant ;
 * - entre camps simultanément en mouvement vers un même point, l'angle se lit sur leurs vecteurs d'approche : un camp
 *   avance dans la direction de son cap (sa ligne avant devant), et l'adversaire arrive de la direction opposée au
 *   cap de celui-ci. L'écart entre les deux donne l'angle : de face jusqu'à 45°, par l'arrière à partir de 135°, de
 *   flanc entre les deux (valeurs provisoires, §7).
 */
final readonly class EngagementRules
{
    /** Écart (degrés) jusqu'auquel l'adversaire arrive de face */
    public const float FRONT_MAX_DEGREES = 45.0;

    /** Écart (degrés) à partir duquel l'adversaire arrive par l'arrière */
    public const float REAR_MIN_DEGREES = 135.0;

    /**
     * Angle d'attaque de chaque camp.
     *
     * @return array<string, AttackAngle> par valeur de CombatSide
     */
    public function angles(SideMotion $attacker, SideMotion $defender): array
    {
        return [
            CombatSide::Attacker->value => $this->angle($attacker, $defender),
            CombatSide::Defender->value => $this->angle($defender, $attacker),
        ];
    }

    /**
     * Ordre d'engagement des lignes de chaque camp, pour CombatEngine::resolve().
     *
     * @return array<string, list<FormationRow>> par valeur de CombatSide
     */
    public function orders(SideMotion $attacker, SideMotion $defender): array
    {
        return array_map(static fn(AttackAngle $angle): array => $angle->rowOrder(), $this->angles($attacker, $defender));
    }

    /** Angle sous lequel un camp est attaqué par l'autre */
    public function angle(SideMotion $side, SideMotion $enemy): AttackAngle
    {
        // À l'arrêt, on fait face ; face à une cible à l'arrêt, on fonce sur elle ligne avant devant
        if ($side->stationary || $enemy->stationary) {
            return AttackAngle::Front;
        }
        $degrees = $this->degreesBetween($side->headingX, $side->headingY, null === $enemy->headingX ? null : -$enemy->headingX, null === $enemy->headingY ? null : -$enemy->headingY);
        if (null === $degrees || $degrees <= self::FRONT_MAX_DEGREES + 1e-6) {
            return AttackAngle::Front;
        }

        return $degrees >= self::REAR_MIN_DEGREES - 1e-6 ? AttackAngle::Rear : AttackAngle::Flank;
    }

    /** Écart entre deux directions, de 0 à 180° ; null si l'une est inconnue ou nulle (de face par défaut) */
    private function degreesBetween(?float $ax, ?float $ay, ?float $bx, ?float $by): ?float
    {
        if (null === $ax || null === $ay || null === $bx || null === $by) {
            return null;
        }
        $norms = hypot($ax, $ay) * hypot($bx, $by);
        if ($norms < 1e-9) {
            return null;
        }

        return rad2deg(acos(max(-1.0, min(1.0, ($ax * $bx + $ay * $by) / $norms))));
    }
}
