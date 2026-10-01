<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\GlobalPosition;
use App\Factory\GalaxyFactory;
use App\Factory\StarSystemFactory;
use App\Universe\Generation\PlanetGenerator;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\Persistence\flush_after;
use function Zenstruck\Foundry\Persistence\save;

/**
 * Données de développement (make fixtures). L'univers d'exemple sera remplacé par une galaxie
 * générée dès que la commande de génération existera (#10).
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    private const int SYSTEMS = 12;
    private const int SEED = 1;

    public function build(): void
    {
        $galaxy = GalaxyFactory::createOne(['number' => 1, 'name' => 'Voie des Gardiens']);
        $randomizer = new Randomizer(new Mt19937(self::SEED));
        $planetGenerator = new PlanetGenerator();

        flush_after(static function () use ($galaxy, $randomizer, $planetGenerator): void {
            for ($number = 1; $number <= self::SYSTEMS; ++$number) {
                // Systèmes répartis sur une spirale autour du centre (0 ; 0)
                $angle = $number * 2.4;
                $distance = 250.0 * $number;
                $system = StarSystemFactory::createOne([
                    'galaxy' => $galaxy,
                    'number' => $number,
                    'position' => new GlobalPosition($distance * cos($angle), $distance * sin($angle)),
                ]);

                foreach ($planetGenerator->populate($system, $randomizer) as $planet) {
                    save($planet);
                }
            }
        });
    }
}
