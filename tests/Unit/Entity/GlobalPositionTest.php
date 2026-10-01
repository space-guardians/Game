<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GlobalPosition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlobalPositionTest extends TestCase
{
    public function testComputesDistanceBetweenTwoPositions(): void
    {
        $from = new GlobalPosition(1.0, 2.0);
        $to = new GlobalPosition(4.0, 6.0);

        self::assertEqualsWithDelta(5.0, $from->distanceTo($to), 1e-9);
        self::assertEqualsWithDelta(5.0, $to->distanceTo($from), 1e-9);
    }

    public function testComputesDistanceFromGalaxyCenter(): void
    {
        self::assertEqualsWithDelta(13.0, (new GlobalPosition(-5.0, 12.0))->distanceFromCenter(), 1e-9);
        self::assertSame(0.0, (new GlobalPosition(0.0, 0.0))->distanceFromCenter());
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function nonFiniteCoordinates(): iterable
    {
        yield 'x infini' => [INF, 0.0];
        yield 'y NaN' => [0.0, NAN];
    }

    #[DataProvider('nonFiniteCoordinates')]
    public function testRejectsNonFiniteCoordinates(float $x, float $y): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GlobalPosition($x, $y);
    }
}
