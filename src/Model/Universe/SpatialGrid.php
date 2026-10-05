<?php

declare(strict_types=1);

namespace App\Model\Universe;

use App\Entity\GlobalPosition;

/**
 * Grille de hachage spatial : retrouve en temps constant les positions voisines d'un point,
 * au lieu de comparer chaque candidat à tous les systèmes déjà placés.
 */
final class SpatialGrid
{
    /** @var array<string, list<GlobalPosition>> */
    private array $cells = [];

    public function __construct(
        private readonly float $cellSize,
    ) {
        if ($cellSize <= 0) {
            throw new \InvalidArgumentException('La taille des cellules doit être strictement positive.');
        }
    }

    public function add(GlobalPosition $position): void
    {
        $this->cells[$this->key($this->cell($position->x), $this->cell($position->y))][] = $position;
    }

    public function hasNeighbourWithin(GlobalPosition $position, float $distance): bool
    {
        $reach = (int) ceil($distance / $this->cellSize);
        $cx = $this->cell($position->x);
        $cy = $this->cell($position->y);

        for ($x = $cx - $reach; $x <= $cx + $reach; ++$x) {
            for ($y = $cy - $reach; $y <= $cy + $reach; ++$y) {
                foreach ($this->cells[$this->key($x, $y)] ?? [] as $other) {
                    if ($position->distanceTo($other) < $distance) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function cell(float $coordinate): int
    {
        return (int) floor($coordinate / $this->cellSize);
    }

    private function key(int $x, int $y): string
    {
        return $x . ':' . $y;
    }
}
