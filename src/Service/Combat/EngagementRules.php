<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationRow;
use App\Model\Combat\SideMotion;

/**
 * Ordre d'engagement des lignes de formation de chaque camp (§4.6.2, §4.7), à passer au moteur de combat :
 * - une flotte stationnée ne peut jamais être prise par surprise : elle fait toujours face à l'attaquant, qui engage
 *   sa ligne avant en premier, à une planète, un système ou toute autre position ; il n'existe pas de bonus
 *   « attaque par l'arrière » contre une cible à l'arrêt ;
 * - l'attaquant, qui arrive sur sa cible, lui présente aussi sa ligne avant ;
 * - seules des flottes simultanément en mouvement vers un même point peuvent s'engager de flanc ou par l'arrière :
 *   l'angle d'attaque entre flottes convergentes (#44) ; d'ici là, de front.
 */
final readonly class EngagementRules
{
    public function __construct(
        private CombatRules $rules,
    ) {}

    /**
     * @return array<string, list<FormationRow>> par valeur de CombatSide
     */
    public function orders(SideMotion $attacker, SideMotion $defender): array
    {
        return [
            CombatSide::Attacker->value => $this->rules->frontalOrder(),
            CombatSide::Defender->value => $defender->stationary || $attacker->stationary
                ? $this->rules->frontalOrder()
                : $this->convergent($attacker, $defender),
        ];
    }

    /**
     * Deux camps en mouvement : la ligne engagée en premier dépendra de leurs vecteurs d'approche (#44). En attendant,
     * engagement de front, comme pour une cible à l'arrêt.
     *
     * @return list<FormationRow>
     */
    private function convergent(SideMotion $attacker, SideMotion $defender): array
    {
        return $this->rules->frontalOrder();
    }
}
