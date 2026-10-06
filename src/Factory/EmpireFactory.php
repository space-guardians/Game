<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Empire;
use App\Enum\Account\StartingOrientation;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Empire d'un joueur, avec son compte et sa planète mère (créés au besoin).
 *
 * @extends PersistentObjectFactory<Empire>
 */
final class EmpireFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Empire::class;
    }

    protected function defaults(): array
    {
        return [
            'user' => UserFactory::new(),
            'name' => 'Ordre ' . self::faker()->unique()->numberBetween(1, 999_999),
            'orientation' => self::faker()->randomElement(StartingOrientation::cases()),
            'homePlanet' => PlanetFactory::new(),
            'foundedAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
        ];
    }
}
