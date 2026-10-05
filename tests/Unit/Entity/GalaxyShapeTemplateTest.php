<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GalaxyShapeTemplate;
use App\Model\Universe\SpiralGalaxyShape;
use PHPUnit\Framework\TestCase;

final class GalaxyShapeTemplateTest extends TestCase
{
    public function testNewTemplateUsesDefaultShape(): void
    {
        self::assertEquals(new SpiralGalaxyShape(), (new GalaxyShapeTemplate('Classique'))->toShape());
    }

    public function testRoundTripsShapeParameters(): void
    {
        $shape = new SpiralGalaxyShape(arms: 6, armTightness: 1.2, armWidth: 0.4, coreRadius: 900.0, diskScale: 8_000.0, interArmDensity: 0.2);

        self::assertEquals($shape, GalaxyShapeTemplate::fromShape('Floconneuse', $shape)->toShape());
    }
}
