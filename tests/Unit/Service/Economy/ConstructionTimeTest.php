<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Economy;

use App\Model\Economy\Resources;
use App\Service\Economy\BuildingRules;
use App\Twig\GameFormatExtension;
use PHPUnit\Framework\TestCase;

final class ConstructionTimeTest extends TestCase
{
    public function testDurationDependsOnCostAndFactories(): void
    {
        $rules = new BuildingRules();
        // 60 métal + 15 cristal : 75 / 2 500 h = 108 s
        $cost = new Resources(60, 15);

        self::assertSame(108, $rules->constructionSeconds($cost, 0, 0, 1.0));
        self::assertSame(54, $rules->constructionSeconds($cost, 1, 0, 1.0));
        self::assertSame(27, $rules->constructionSeconds($cost, 1, 1, 1.0));
        self::assertSame(36, $rules->constructionSeconds($cost, 0, 0, 3.0));
    }

    public function testDurationIsAtLeastOneSecond(): void
    {
        self::assertSame(1, new BuildingRules()->constructionSeconds(new Resources(1), 10, 10, 100.0));
    }

    public function testResourcesArithmetic(): void
    {
        $stock = new Resources(500, 500, 0);

        self::assertTrue($stock->covers(new Resources(60, 15)));
        self::assertFalse($stock->covers(new Resources(900, 360, 180)));
        self::assertEquals(new Resources(440, 485, 0), $stock->minus(new Resources(60, 15)));
        self::assertEquals(new Resources(400, 0, 180), $stock->shortfall(new Resources(900, 360, 180)));
    }

    public function testCannotSpendMoreThanAvailable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Resources(10)->minus(new Resources(11));
    }

    public function testFormatsDurationsLikeTheCharter(): void
    {
        $format = new GameFormatExtension();

        self::assertSame('45 s', $format->duration(45));
        self::assertSame('3 min 05 s', $format->duration(185));
        self::assertSame('2 h 14 min', $format->duration(2 * 3600 + 14 * 60 + 30));
        self::assertSame('3 j 4 h', $format->duration(3 * 86_400 + 4 * 3600));
    }
}
