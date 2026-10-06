<?php

declare(strict_types=1);

namespace App\Model\Research;

/**
 * Lien de l'arbre : la technologie requise (from) débloque, à partir d'un niveau, la technologie cible (to).
 */
final readonly class TechTreeEdge
{
    public function __construct(
        public string $from,
        public string $to,
        public int $level,
        /** Tracé SVG (courbe de Bézier du bord droit de la source au bord gauche de la cible) */
        public string $path,
    ) {}
}
