<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\GalaxyShapeTemplate;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Gabarits avec les réglages par défaut de SpiralGalaxyShape, ajustables par attribut.
 *
 * @extends PersistentObjectFactory<GalaxyShapeTemplate>
 */
final class GalaxyShapeTemplateFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return GalaxyShapeTemplate::class;
    }

    protected function defaults(): array
    {
        return ['name' => 'Spirale ' . self::faker()->unique()->word()];
    }
}
