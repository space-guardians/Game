<?php

declare(strict_types=1);

namespace App\Universe\Generation;

use App\Entity\Galaxy;
use App\Entity\StarSystem;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Génère une galaxie complète (systèmes et planètes) à partir d'une graine : la même graine et les mêmes
 * paramètres produisent toujours la même galaxie, ce qui rend la génération rejouable (tests, débogage).
 *
 * Les objets sont créés en mémoire, sans être persistés. Les systèmes sont numérotés dans l'ordre de
 * génération : le système n°1 est le plus proche du centre.
 *
 * @see §2.2 et §5.5 du cahier des charges
 */
final readonly class GalaxyGenerator
{
    public function __construct(
        private SystemPlacer $systemPlacer,
        private PlanetGenerator $planetGenerator,
    ) {}

    public function generate(
        int $number,
        string $name,
        int $seed,
        SpiralGalaxyShape $shape = new SpiralGalaxyShape(),
        int $systemCount = 1000,
        float $minDistance = SystemPlacer::DEFAULT_MIN_DISTANCE,
    ): Galaxy {
        $randomizer = new Randomizer(new Mt19937($seed));
        $galaxy = new Galaxy($number, $name);

        foreach ($this->systemPlacer->place($shape, $systemCount, $minDistance, $randomizer) as $index => $position) {
            $this->planetGenerator->populate(new StarSystem($galaxy, $index + 1, $position), $randomizer);
        }

        return $galaxy;
    }
}
