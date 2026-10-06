<?php

declare(strict_types=1);

namespace App\Model\Research;

use App\Entity\Technology;

/**
 * Nœud de l'arbre : une technologie, sa colonne (profondeur de ses prérequis) et sa position en pixels.
 */
final readonly class TechTreeNode
{
    public function __construct(
        public Technology $technology,
        public int $column,
        public int $row,
        public int $x,
        public int $y,
    ) {}
}
