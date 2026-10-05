<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Universe;

use App\Entity\GlobalPosition;
use App\Model\Universe\SpatialGrid;
use PHPUnit\Framework\TestCase;

final class SpatialGridTest extends TestCase
{
    public function testFindsNeighbourInAdjacentCell(): void
    {
        $grid = new SpatialGrid(100.0);
        $grid->add(new GlobalPosition(99.0, 0.0));

        // Cellule voisine, mais à 2 unités seulement
        self::assertTrue($grid->hasNeighbourWithin(new GlobalPosition(101.0, 0.0), 10.0));
    }

    public function testFindsNeighbourFartherThanOneCell(): void
    {
        $grid = new SpatialGrid(50.0);
        $grid->add(new GlobalPosition(0.0, 0.0));

        self::assertTrue($grid->hasNeighbourWithin(new GlobalPosition(-140.0, -10.0), 150.0));
    }

    public function testIgnoresPositionsBeyondDistance(): void
    {
        $grid = new SpatialGrid(100.0);
        $grid->add(new GlobalPosition(0.0, 0.0));

        self::assertFalse($grid->hasNeighbourWithin(new GlobalPosition(60.0, 80.0), 100.0));
        self::assertFalse($grid->hasNeighbourWithin(new GlobalPosition(5_000.0, 0.0), 100.0));
    }

    public function testRejectsNonPositiveCellSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SpatialGrid(0.0);
    }
}
