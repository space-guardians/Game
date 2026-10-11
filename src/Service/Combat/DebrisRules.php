<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Model\Economy\Resources;

/**
 * Règles des champs de débris (§4.6, §4.7), sans état ni base. Valeurs provisoires avant l'équilibrage (§7) :
 * - un vaisseau détruit laisse 30 % de son coût en métal et en cristal (le deutérium se consume) ;
 * - des recycleurs récupèrent un champ dans la limite de leur cargo (et de la place libre de la flotte), métal et
 *   cristal au prorata de ce que contient le champ.
 */
final readonly class DebrisRules
{
    public const float DEBRIS_RATIO = 0.3;

    /**
     * Débris laissés par des vaisseaux détruits.
     *
     * @param list<array{cost: Resources, count: int}> $destroyed
     */
    public function fromDestroyed(array $destroyed): Resources
    {
        $metal = 0.0;
        $crystal = 0.0;
        foreach ($destroyed as ['cost' => $cost, 'count' => $count]) {
            $metal += $cost->metal * max(0, $count) * self::DEBRIS_RATIO;
            $crystal += $cost->crystal * max(0, $count) * self::DEBRIS_RATIO;
        }

        return new Resources($metal, $crystal);
    }

    /** Part d'un champ récupérable avec une capacité donnée, au prorata métal / cristal */
    public function collectable(Resources $field, float $capacity): Resources
    {
        $total = $field->metal + $field->crystal;
        if ($total <= 0 || $capacity <= 0) {
            return new Resources();
        }
        $share = min(1.0, $capacity / $total);

        return new Resources(floor($field->metal * $share), floor($field->crystal * $share));
    }
}
