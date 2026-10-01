<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\OrbitalPosition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrbitalPositionTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float}>
     */
    public static function angles(): iterable
    {
        yield 'déjà normalisé' => [1.0, 1.0];
        yield 'au-delà d\'un tour' => [2 * M_PI + 0.5, 0.5];
        yield 'négatif' => [-M_PI / 2, 3 * M_PI / 2];
        yield 'tour complet' => [2 * M_PI, 0.0];
    }

    #[DataProvider('angles')]
    public function testNormalizesAngleWithinOneTurn(float $angle, float $expected): void
    {
        self::assertEqualsWithDelta($expected, (new OrbitalPosition(3, 30.0, $angle))->angle, 1e-9);
    }

    public function testConvertsToCartesianCoordinatesRelativeToStar(): void
    {
        $quarterTurn = (new OrbitalPosition(2, 20.0, M_PI / 2))->toLocalCartesian();
        $halfTurn = (new OrbitalPosition(2, 20.0, M_PI))->toLocalCartesian();

        self::assertEqualsWithDelta(0.0, $quarterTurn['x'], 1e-9);
        self::assertEqualsWithDelta(20.0, $quarterTurn['y'], 1e-9);
        self::assertEqualsWithDelta(-20.0, $halfTurn['x'], 1e-9);
        self::assertEqualsWithDelta(0.0, $halfTurn['y'], 1e-9);
    }

    /**
     * @return iterable<string, array{int, float, float}>
     */
    public static function invalidPositions(): iterable
    {
        yield 'orbite 0' => [0, 10.0, 0.0];
        yield 'orbite au-delà du maximum' => [OrbitalPosition::MAX_ORBIT + 1, 10.0, 0.0];
        yield 'rayon nul' => [1, 0.0, 0.0];
        yield 'rayon négatif' => [1, -5.0, 0.0];
        yield 'angle infini' => [1, 10.0, INF];
    }

    #[DataProvider('invalidPositions')]
    public function testRejectsInvalidPosition(int $orbit, float $radius, float $angle): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrbitalPosition($orbit, $radius, $angle);
    }
}
