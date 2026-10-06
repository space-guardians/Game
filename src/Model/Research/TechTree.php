<?php

declare(strict_types=1);

namespace App\Model\Research;

/**
 * Disposition de l'arbre de recherche (§4.4, §5.5), calculée côté serveur : nœuds positionnés en pixels et liens
 * entre une technologie requise et celles qu'elle débloque.
 */
final readonly class TechTree
{
    /**
     * @param list<TechTreeNode> $nodes
     * @param list<TechTreeEdge> $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public int $width,
        public int $height,
    ) {}
}
