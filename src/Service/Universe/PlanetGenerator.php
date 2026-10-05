<?php

declare(strict_types=1);

namespace App\Service\Universe;

use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;
use Random\Randomizer;

/**
 * Génère les planètes d'un système stellaire : entre 3 et 15, réparties sur les 15 orbites
 * concentriques possibles (les orbites restantes sont des emplacements vides).
 *
 * Le rayon croît avec le numéro d'orbite et reste sous la moitié de la distance minimale entre
 * systèmes ; la température baisse en s'éloignant de l'étoile. Résultat déterministe pour un
 * Randomizer initialisé avec la même graine.
 *
 * @see §2.2 du cahier des charges
 */
final readonly class PlanetGenerator
{
    public const int MIN_PLANETS = 3;
    public const int MAX_PLANETS = OrbitalPosition::MAX_ORBIT;

    /** Rayon de la première orbite et écart entre deux orbites successives */
    public const float FIRST_ORBIT_RADIUS = 8.0;
    public const float ORBIT_SPACING = 6.0;
    /** Décalage aléatoire du rayon autour de sa valeur nominale, assez faible pour garder les orbites ordonnées */
    private const float RADIUS_JITTER = 1.5;

    /** Température (°C) de la première orbite, perte par orbite et variation aléatoire */
    private const int FIRST_ORBIT_TEMPERATURE = 230;
    private const int TEMPERATURE_LOSS_PER_ORBIT = 18;
    private const int TEMPERATURE_JITTER = 20;

    /**
     * Crée les planètes et les rattache au système (sans les persister).
     *
     * @return list<Planet> triées par orbite croissante
     */
    public function populate(StarSystem $system, Randomizer $randomizer): array
    {
        $count = $randomizer->getInt(self::MIN_PLANETS, self::MAX_PLANETS);
        $orbits = $randomizer->pickArrayKeys(array_fill(1, OrbitalPosition::MAX_ORBIT, true), $count);
        sort($orbits);

        $planets = [];
        foreach ($orbits as $orbit) {
            $planets[] = new Planet(
                $system,
                new OrbitalPosition($orbit, $this->radius($orbit, $randomizer), $randomizer->getFloat(0, 2 * M_PI)),
                $this->temperature($orbit, $randomizer),
            );
        }

        return $planets;
    }

    /** Rayon maximal possible : celui de la dernière orbite, décalage compris */
    public static function maxRadius(): float
    {
        return self::nominalRadius(OrbitalPosition::MAX_ORBIT) + self::RADIUS_JITTER;
    }

    private static function nominalRadius(int $orbit): float
    {
        return self::FIRST_ORBIT_RADIUS + ($orbit - 1) * self::ORBIT_SPACING;
    }

    private function radius(int $orbit, Randomizer $randomizer): float
    {
        return self::nominalRadius($orbit) + $randomizer->getFloat(-self::RADIUS_JITTER, self::RADIUS_JITTER);
    }

    private function temperature(int $orbit, Randomizer $randomizer): int
    {
        return self::FIRST_ORBIT_TEMPERATURE
            - ($orbit - 1) * self::TEMPERATURE_LOSS_PER_ORBIT
            + $randomizer->getInt(-self::TEMPERATURE_JITTER, self::TEMPERATURE_JITTER);
    }
}
