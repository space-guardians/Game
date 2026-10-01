<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Factory\GalaxyFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Données de développement (make fixtures). L'univers d'exemple sera remplacé par une galaxie
 * générée dès que la commande de génération existera (#10).
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    private const int SYSTEMS = 12;

    public function build(): void
    {
        $galaxy = GalaxyFactory::createOne(['number' => 1, 'name' => 'Voie des Gardiens']);

        for ($number = 1; $number <= self::SYSTEMS; ++$number) {
            // Systèmes répartis sur une spirale autour du centre (0 ; 0)
            $angle = $number * 2.4;
            $distance = 150.0 * $number;
            $system = StarSystemFactory::createOne([
                'galaxy' => $galaxy,
                'number' => $number,
                'position' => new GlobalPosition($distance * cos($angle), $distance * sin($angle)),
            ]);

            $planets = 3 + $number % (OrbitalPosition::MAX_ORBIT - 2);
            for ($orbit = 1; $orbit <= $planets; ++$orbit) {
                PlanetFactory::createOne([
                    'system' => $system,
                    'position' => new OrbitalPosition($orbit, 10.0 * $orbit, $orbit * 1.3),
                    // Plus chaud près de l'étoile
                    'temperature' => 220 - 25 * $orbit,
                ]);
            }
        }
    }
}
