<?php

declare(strict_types=1);

namespace App\Universe\Generation;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Aperçu d'une forme de galaxie : les systèmes placés par l'algorithme de génération réel, ramenés dans une
 * boîte SVG (-100 ; 100) centrée sur (0 ; 0). Sert à l'illustration d'accueil et à l'aperçu des gabarits.
 */
final readonly class GalaxyPreview
{
    /** Couleurs des systèmes, des plus courants aux plus rares (charte graphique §2.3) */
    private const array COLORS = ['#3A4A70', '#7A8AAE', '#6FDCEE', '#F2B84B'];

    public function __construct(
        private SystemPlacer $systemPlacer,
    ) {}

    /**
     * @return list<array{x: float, y: float, r: float, color: string}>
     */
    public function stars(SpiralGalaxyShape $shape, int $systems = 700, int $seed = 7): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));
        $positions = $this->systemPlacer->place($shape, $systems, SystemPlacer::DEFAULT_MIN_DISTANCE, $randomizer);
        $extent = max(1.0, ...array_map(static fn($p): float => $p->distanceFromCenter(), $positions));

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
    }
}
