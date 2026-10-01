<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Universe\Generation\SpiralGalaxyShape;
use App\Universe\Generation\SystemPlacer;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Illustration galactique des écrans d'accueil (inscription, connexion) : une galaxie spirale produite par
 * l'algorithme de génération du jeu, avec une graine fixe, et mise en cache.
 */
#[AsTwigComponent]
final class GalaxyBackdrop
{
    private const int SYSTEMS = 700;
    private const int SEED = 7;
    /** Couleurs des systèmes, des plus courants aux plus rares (charte §2.3) */
    private const array COLORS = ['#3A4A70', '#7A8AAE', '#6FDCEE', '#F2B84B'];

    public function __construct(
        private readonly SystemPlacer $systemPlacer,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Points en coordonnées de la boîte SVG (-100 ; 100), centre de la galaxie en (0 ; 0).
     *
     * @return list<array{x: float, y: float, r: float, color: string}>
     */
    public function getStars(): array
    {
        return $this->cache->get('galaxy_backdrop_' . self::SEED, function (): array {
            $randomizer = new Randomizer(new Mt19937(self::SEED));
            $positions = $this->systemPlacer->place(new SpiralGalaxyShape(), self::SYSTEMS, SystemPlacer::DEFAULT_MIN_DISTANCE, $randomizer);
            $extent = max(array_map(static fn($p): float => $p->distanceFromCenter(), $positions));

            $stars = [];
            foreach ($positions as $position) {
                $brightness = $randomizer->nextFloat();
                $stars[] = [
                    'x' => round($position->x / $extent * 92, 2),
                    'y' => round($position->y / $extent * 92, 2),
                    'r' => $brightness > 0.92 ? 0.55 : ($brightness > 0.6 ? 0.42 : 0.3),
                    'color' => self::COLORS[match (true) {
                        $brightness > 0.97 => 3,
                        $brightness > 0.9 => 2,
                        $brightness > 0.6 => 1,
                        default => 0,
                    }],
                ];
            }

            return $stars;
        });
    }
}
