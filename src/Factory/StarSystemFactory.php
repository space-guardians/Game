<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\GlobalPosition;
use App\Entity\StarSystem;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<StarSystem>
 */
final class StarSystemFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return StarSystem::class;
    }

    protected function defaults(): array
    {
        return [
            'galaxy' => GalaxyFactory::new(),
            'number' => self::faker()->unique()->numberBetween(1, 1_000_000),
            'position' => new GlobalPosition(
                self::faker()->randomFloat(3, -5000, 5000),
                self::faker()->randomFloat(3, -5000, 5000),
            ),
        ];
    }
}
