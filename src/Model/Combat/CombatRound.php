<?php

declare(strict_types=1);

namespace App\Model\Combat;

use App\Enum\Combat\CombatSide;

/** Bilan d'un round (§4.7), par camp tireur : de quoi alimenter le rapport de combat */
final readonly class CombatRound
{
    /**
     * @param array<string, int>   $shots    tirs, par camp (valeur de CombatSide)
     * @param array<string, int>   $hits     tirs au but, par camp
     * @param array<string, float> $absorbed dégâts arrêtés par les boucliers adverses, par camp tireur
     * @param array<string, float> $damage   dégâts portés aux coques adverses, par camp tireur
     * @param array<string, int>   $losses   vaisseaux détruits ce round, par clé de groupe
     */
    public function __construct(
        public int $number,
        public array $shots,
        public array $hits,
        public array $absorbed,
        public array $damage,
        public array $losses,
    ) {}

    public function shotsBy(CombatSide $side): int
    {
        return $this->shots[$side->value] ?? 0;
    }

    public function hitsBy(CombatSide $side): int
    {
        return $this->hits[$side->value] ?? 0;
    }

    public function damageBy(CombatSide $side): float
    {
        return $this->damage[$side->value] ?? 0.0;
    }
}
