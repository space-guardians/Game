<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Economy;

use App\Model\Economy\Resources;
use App\Service\Economy\CancellationRefund;
use PHPUnit\Framework\TestCase;

final class CancellationRefundTest extends TestCase
{
    private CancellationRefund $refund;

    protected function setUp(): void
    {
        $this->refund = new CancellationRefund();
    }

    public function testRefundsShareOfRemainingTime(): void
    {
        $start = new \DateTimeImmutable('2026-10-06 10:00:00');
        $end = new \DateTimeImmutable('2026-10-06 10:10:00');

        // Exemple du cahier des charges : annulation à 10 % du temps écoulé → 90 % du coût
        self::assertEqualsWithDelta(0.9, $this->refund->remainingShare($start, $end, new \DateTimeImmutable('2026-10-06 10:01:00')), 1e-12);
        self::assertSame(1.0, $this->refund->remainingShare($start, $end, $start));
        self::assertSame(0.0, $this->refund->remainingShare($start, $end, $end));
        self::assertSame(0.0, $this->refund->remainingShare($start, $end, new \DateTimeImmutable('2026-10-06 11:00:00')));
    }

    public function testCreditIsCappedByStorageCapacity(): void
    {
        $credited = $this->refund->credit(new Resources(9_900, 100, 0), new Resources(500, 50, 10), new Resources(10_000, 10_000, 10_000));

        self::assertEquals(new Resources(10_000, 150, 10), $credited);
    }

    public function testStockAlreadyOverCapacityReceivesNothingAndLosesNothing(): void
    {
        $credited = $this->refund->credit(new Resources(12_000), new Resources(500), new Resources(10_000));

        self::assertEquals(new Resources(12_000), $credited);
    }
}
