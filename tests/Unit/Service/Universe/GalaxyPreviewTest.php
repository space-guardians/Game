<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Universe;

use App\Model\Universe\SpiralGalaxyShape;
use App\Service\Universe\GalaxyPreview;
use App\Service\Universe\SystemPlacer;
use PHPUnit\Framework\TestCase;

final class GalaxyPreviewTest extends TestCase
{
    public function testFitsRequestedSystemsInsidePreviewBox(): void
    {
        $stars = (new GalaxyPreview(new SystemPlacer()))->stars(new SpiralGalaxyShape(arms: 2), 300);

        self::assertCount(300, $stars);
        foreach ($stars as $star) {
            self::assertLessThanOrEqual(92.0, hypot($star['x'], $star['y']) - 0.01);
        }
    }

    public function testIsStableForTheSameShape(): void
    {
        $preview = new GalaxyPreview(new SystemPlacer());

        self::assertSame($preview->stars(new SpiralGalaxyShape(), 200), $preview->stars(new SpiralGalaxyShape(), 200));
        self::assertNotSame($preview->stars(new SpiralGalaxyShape(), 200), $preview->stars(new SpiralGalaxyShape(arms: 3), 200));
    }
}
