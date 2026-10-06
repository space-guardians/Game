<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ScheduledEvent;
use App\Enum\Scheduling\ScheduledEventStatus;
use PHPUnit\Framework\TestCase;

final class ScheduledEventTest extends TestCase
{
    public function testIsDueOnlyWhilePendingAndPastItsDueTime(): void
    {
        $event = $this->event('2026-10-06 12:00:00');

        self::assertFalse($event->isDue(new \DateTimeImmutable('2026-10-06 11:59:59')));
        self::assertTrue($event->isDue(new \DateTimeImmutable('2026-10-06 12:00:00')));

        $event->markDone(new \DateTimeImmutable('2026-10-06 12:00:01'));
        self::assertSame(ScheduledEventStatus::Done, $event->getStatus());
        self::assertFalse($event->isDue(new \DateTimeImmutable('2026-10-06 13:00:00')));
    }

    public function testCannotBeResolvedTwice(): void
    {
        $event = $this->event('2026-10-06 12:00:00');
        $event->markDone(new \DateTimeImmutable('2026-10-06 12:00:00'));

        $this->expectException(\LogicException::class);

        $event->markFailed('Trop tard', new \DateTimeImmutable('2026-10-06 12:00:00'));
    }

    public function testCancelledEventIsNeverDue(): void
    {
        $event = $this->event('2026-10-06 12:00:00');

        $event->cancel(new \DateTimeImmutable('2026-10-06 11:00:00'));

        self::assertSame(ScheduledEventStatus::Cancelled, $event->getStatus());
        self::assertFalse($event->isDue(new \DateTimeImmutable('2026-10-06 13:00:00')));
    }

    private function event(string $dueAt): ScheduledEvent
    {
        return new ScheduledEvent('test.record', new \DateTimeImmutable($dueAt), new \DateTimeImmutable('2026-10-06 10:00:00'));
    }
}
