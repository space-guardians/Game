<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Plusieurs planètes d'un même système doivent préciser leur « position » : l'orbite est unique par système.
 *
 * @extends PersistentObjectFactory<Planet>
 */
final class PlanetFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Planet::class;
    }

    protected function defaults(): array
    {
        $orbit = self::faker()->numberBetween(1, OrbitalPosition::MAX_ORBIT);

        return [
            'system' => StarSystemFactory::new(),
            'position' => new OrbitalPosition($orbit, $orbit * 10.0, self::faker()->randomFloat(4, 0, 2 * M_PI)),
            'temperature' => self::faker()->numberBetween(-150, 250),
        ];
    }
}
