<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Fleet;

use App\Service\Fleet\TravelRules;
use PHPUnit\Framework\TestCase;

final class TravelRulesTest extends TestCase
{
    public function testDriveTechnologyRaisesShipSpeed(): void
    {
        $rules = new TravelRules();

        self::assertEqualsWithDelta(5000.0, $rules->shipSpeed(5000, 'combustion_drive', 0), 1e-9);
        self::assertEqualsWithDelta(6000.0, $rules->shipSpeed(5000, 'combustion_drive', 2), 1e-9);
        self::assertEqualsWithDelta(14000.0, $rules->shipSpeed(10000, 'impulse_drive', 2), 1e-9);
        self::assertEqualsWithDelta(13000.0, $rules->shipSpeed(10000, 'hyperspace_drive', 1), 1e-9);
        self::assertEqualsWithDelta(10000.0, $rules->shipSpeed(10000, null, 5), 1e-9);
    }

    public function testDurationFromDistanceSpeedPercentAndUniverseSpeed(): void
    {
        $rules = new TravelRules();

        // 35 000 / 100 × √(10 × 1 000 / 10 000) + 10 = 360 s
        self::assertSame(360, $rules->durationSeconds(1000, 10000, 100, 1.0));
        self::assertSame(710, $rules->durationSeconds(1000, 10000, 50, 1.0));
        self::assertSame(180, $rules->durationSeconds(1000, 10000, 100, 2.0));
        // Quatre fois plus loin : deux fois plus long (hors durée fixe)
        self::assertSame(710, $rules->durationSeconds(4000, 10000, 100, 1.0));
        self::assertSame(10, $rules->durationSeconds(0, 10000, 100, 1.0));
    }

    public function testSpeedPercentGoesByTens(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TravelRules()->durationSeconds(1000, 10000, 55, 1.0);
    }

    public function testEmptyFleetCannotTravel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TravelRules()->durationSeconds(1000, 0, 100, 1.0);
    }

    public function testProgressIsClampedBetweenDepartureAndArrival(): void
    {
        $rules = new TravelRules();
        $departure = new \DateTimeImmutable('2026-10-06 10:00:00');
        $arrival = new \DateTimeImmutable('2026-10-06 10:10:00');

        self::assertSame(0.0, $rules->progress($departure, $arrival, new \DateTimeImmutable('2026-10-06 09:00:00')));
        self::assertEqualsWithDelta(0.25, $rules->progress($departure, $arrival, new \DateTimeImmutable('2026-10-06 10:02:30')), 1e-9);
        self::assertSame(1.0, $rules->progress($departure, $arrival, new \DateTimeImmutable('2026-10-06 11:00:00')));
    }
}
