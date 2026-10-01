<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Galaxy;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Galaxy>
 */
final class GalaxyFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Galaxy::class;
    }

    protected function defaults(): array
    {
        return [
            'number' => self::faker()->unique()->numberBetween(1, 1_000_000),
            'name' => ucfirst(self::faker()->words(2, true)),
        ];
    }
}
