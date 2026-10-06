<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Economy;

use App\Model\Economy\Resources;
use App\Service\Economy\ResourceAccumulator;
use PHPUnit\Framework\TestCase;

final class ResourceAccumulatorTest extends TestCase
{
    private ResourceAccumulator $accumulator;

    protected function setUp(): void
    {
        $this->accumulator = new ResourceAccumulator();
    }

    public function testAddsHourlyProductionTimesElapsedTime(): void
    {
        $result = $this->accumulator->accumulate(new Resources(500, 500, 0), new Resources(30, 15, 6), new Resources(10_000, 10_000, 10_000), 2.5);

        self::assertEquals(new Resources(575, 537.5, 15), $result);
    }

    public function testKeepsFractionsOfProduction(): void
    {
        // 30 de métal par heure : une seconde en produit 1/120
        $result = $this->accumulator->accumulate(Resources::zero(), new Resources(30), new Resources(10_000), 1 / 3600);

        self::assertEqualsWithDelta(1 / 120, $result->metal, 1e-12);
    }

    public function testExcessBeyondCapacityIsLost(): void
    {
        $result = $this->accumulator->accumulate(new Resources(9_990, 100, 0), new Resources(30, 15, 0), new Resources(10_000, 10_000, 10_000), 1.0);

        self::assertEquals(new Resources(10_000, 115, 0), $result);
    }

    public function testStockAboveCapacityIsKeptButStopsGrowing(): void
    {
        // Butin ou remboursement au-delà du stockage : rien n'est retiré, rien n'est ajouté
        $result = $this->accumulator->accumulate(new Resources(12_000), new Resources(30), new Resources(10_000), 3.0);

        self::assertEquals(new Resources(12_000), $result);
    }

    public function testNoElapsedTimeChangesNothing(): void
    {
        $stock = new Resources(1, 2, 3);

        self::assertSame($stock, $this->accumulator->accumulate($stock, new Resources(30), new Resources(10_000), 0.0));
        self::assertSame($stock, $this->accumulator->accumulate($stock, new Resources(30), new Resources(10_000), -1.0));
    }

    public function testRejectsNegativeQuantities(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Resources(-1);
    }
}
