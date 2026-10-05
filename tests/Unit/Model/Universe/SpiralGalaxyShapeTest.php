<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Universe;

use App\Model\Universe\SpiralGalaxyShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpiralGalaxyShapeTest extends TestCase
{
    public function testDensityStaysWithinBounds(): void
    {
        $shape = new SpiralGalaxyShape();

        for ($radius = 0.0; $radius <= 30_000; $radius += 750) {
            for ($angle = -M_PI; $angle <= M_PI; $angle += 0.2) {
                $density = $shape->density($radius, $angle);
                self::assertGreaterThanOrEqual(SpiralGalaxyShape::MIN_DENSITY, $density);
                self::assertLessThanOrEqual(1.0, $density);
            }
        }
    }

    public function testCenterHasMaximalDensity(): void
    {
        self::assertSame(1.0, (new SpiralGalaxyShape())->density(0.0, 0.0));
    }

    public function testArmsAreDenserThanSpaceBetweenThem(): void
    {
        $shape = new SpiralGalaxyShape(arms: 4);
        $radius = 4_000.0;
        $onArm = $this->armAngle($shape, $radius);
        $betweenArms = $onArm + M_PI / 4;

        self::assertSame(1.0, $shape->armProximity($radius, $onArm));
        self::assertGreaterThan(10 * $shape->density($radius, $betweenArms), $shape->density($radius, $onArm));
    }

    public function testDensityDecreasesAwayFromCenterAlongAnArm(): void
    {
        $shape = new SpiralGalaxyShape();

        $near = $shape->density(3_000.0, $this->armAngle($shape, 3_000.0));
        $far = $shape->density(9_000.0, $this->armAngle($shape, 9_000.0));

        self::assertGreaterThan($far, $near);
    }

    public function testRotationTurnsTheArms(): void
    {
        $shape = new SpiralGalaxyShape();
        $angle = $this->armAngle($shape, 4_000.0);

        self::assertEqualsWithDelta(
            $shape->armProximity(4_000.0, $angle),
            $shape->armProximity(4_000.0, $angle + 0.7, 0.7),
            1e-9,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function armCounts(): iterable
    {
        yield '2 branches' => [2];
        yield '3 branches' => [3];
        yield '5 branches' => [5];
    }

    #[DataProvider('armCounts')]
    public function testHasOneDensityPeakPerArmAroundACircle(int $arms): void
    {
        $shape = new SpiralGalaxyShape(arms: $arms);
        $samples = 720;
        $values = [];
        for ($i = 0; $i < $samples; ++$i) {
            $values[] = $shape->armProximity(5_000.0, $i * 2 * M_PI / $samples);
        }

        $peaks = 0;
        for ($i = 0; $i < $samples; ++$i) {
            $previous = $values[($i - 1 + $samples) % $samples];
            $next = $values[($i + 1) % $samples];
            if ($values[$i] > $previous && $values[$i] >= $next && $values[$i] > 0.5) {
                ++$peaks;
            }
        }

        self::assertSame($arms, $peaks);
    }

    /**
     * @return iterable<string, array{array<string, int|float>}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'aucune branche' => [['arms' => 0]];
        yield 'trop de branches' => [['arms' => 13]];
        yield 'branches sans largeur' => [['armWidth' => 0.0]];
        yield 'bulbe nul' => [['coreRadius' => 0.0]];
        yield 'disque nul' => [['diskScale' => -1.0]];
        yield 'densité inter-branches négative' => [['interArmDensity' => -0.1]];
        yield 'densité inter-branches supérieure à 1' => [['interArmDensity' => 1.5]];
    }

    /**
     * @param array<string, int|float> $parameters
     */
    #[DataProvider('invalidParameters')]
    public function testRejectsInvalidParameters(array $parameters): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SpiralGalaxyShape(...$parameters);
    }

    public function testRoundTripsThroughArray(): void
    {
        $shape = new SpiralGalaxyShape(arms: 6, armTightness: 1.5, armWidth: 0.3, coreRadius: 900.0, diskScale: 4_000.0, interArmDensity: 0.1);

        self::assertEquals($shape, SpiralGalaxyShape::fromArray($shape->toArray()));
    }

    /** Angle de la première branche (rotation nulle) au rayon donné */
    private function armAngle(SpiralGalaxyShape $shape, float $radius): float
    {
        return $shape->armTightness * log(1 + $radius / $shape->coreRadius);
    }
}
