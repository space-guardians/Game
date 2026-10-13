<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\GlobalPosition;
use Random\Randomizer;

/**
 * Placement des marchands dans une galaxie (§4.15, valeurs provisoires, cf. §7) : un marchand pour SYSTEMS_PER_MERCHANT
 * systèmes, répartis le plus loin possible les uns des autres (échantillonnage du point le plus éloigné, à partir
 * d'un système tiré au sort), chacun sur une station proche de l'étoile, en deçà de la première orbite. Pur.
 */
final class MerchantRules
{
    public const int SYSTEMS_PER_MERCHANT = 10;

    /** Distance de la station au centre du système, en deçà de la première orbite (PlanetGenerator::FIRST_ORBIT_RADIUS) */
    public const float STATION_RADIUS = 4.0;

    public function count(int $systems): int
    {
        return 0 === $systems ? 0 : max(1, (int) round($systems / self::SYSTEMS_PER_MERCHANT));
    }

    /**
     * Systèmes retenus, par indice dans la liste donnée : chaque nouveau marchand va au système le plus éloigné des
     * marchands déjà placés.
     *
     * @param list<GlobalPosition> $systems
     *
     * @return list<int>
     */
    public function pick(array $systems, int $count, Randomizer $randomizer): array
    {
        if ([] === $systems || $count <= 0) {
            return [];
        }
        $chosen = [$randomizer->getInt(0, \count($systems) - 1)];
        // Distance de chaque système au marchand le plus proche
        $nearest = array_map(static fn(GlobalPosition $position): float => $position->distanceTo($systems[$chosen[0]]), $systems);
        while (\count($chosen) < min($count, \count($systems))) {
            $next = 0;
            foreach ($nearest as $index => $distance) {
                if ($distance > $nearest[$next]) {
                    $next = $index;
                }
            }
            $chosen[] = $next;
            foreach ($systems as $index => $position) {
                $nearest[$index] = min($nearest[$index], $position->distanceTo($systems[$next]));
            }
        }

        return $chosen;
    }

    /** @return array{float, float} coordonnées locales de la station, à angle tiré au sort */
    public function station(Randomizer $randomizer): array
    {
        $angle = $randomizer->getFloat(0.0, 2 * M_PI);

        return [round(self::STATION_RADIUS * cos($angle), 3), round(self::STATION_RADIUS * sin($angle), 3)];
    }
}
