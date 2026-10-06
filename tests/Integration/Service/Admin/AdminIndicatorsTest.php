<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Admin;

use App\Entity\ScheduledEvent;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use App\Service\Admin\AdminIndicators;
use App\Service\Economy\BuildingCompletedHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Zenstruck\Foundry\Test\Factories;

/**
 * Indicateurs clés du tableau de bord d'administration (§5.6.1).
 */
final class AdminIndicatorsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testCountsRegisteredActiveAndNewPlayers(): void
    {
        self::mockTime('2026-10-06 12:00:00');
        $users = self::getContainer()->get(UserRepository::class);
        $today = UserFactory::createOne(['registeredAt' => new \DateTimeImmutable('2026-10-06 08:00:00')]);
        $thisWeek = UserFactory::createOne(['registeredAt' => new \DateTimeImmutable('2026-10-02 08:00:00')]);
        UserFactory::createOne(['registeredAt' => new \DateTimeImmutable('2026-08-01 08:00:00')]);
        $users->recordActivity($today, new \DateTimeImmutable('2026-10-06 11:55:00'));
        $users->recordActivity($thisWeek, new \DateTimeImmutable('2026-10-01 20:00:00'));

        $indicators = self::getContainer()->get(AdminIndicators::class)->current();

        self::assertSame(3, $indicators->registeredPlayers);
        self::assertSame(1, $indicators->activeToday);
        self::assertSame(2, $indicators->activeThisWeek);
        self::assertSame(1, $indicators->newToday);
        self::assertSame(2, $indicators->newThisWeek);
    }

    public function testCountsPendingEventsByTypeAndOperationAlerts(): void
    {
        $now = self::mockTime('2026-10-06 12:00:00')->now();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $event = static fn(string $type, string $dueAt): ScheduledEvent => new ScheduledEvent($type, new \DateTimeImmutable($dueAt), $now);
        $pending = [
            $event(BuildingCompletedHandler::TYPE, '2026-10-06 13:00:00'),
            // Échu depuis 1 minute : la vérification périodique peut ne pas être encore passée
            $event(BuildingCompletedHandler::TYPE, '2026-10-06 11:59:00'),
            // Échu depuis 10 minutes : en retard
            $event('fleet.arrival', '2026-10-06 11:50:00'),
        ];
        $failed = $event(BuildingCompletedHandler::TYPE, '2026-10-06 11:00:00');
        $failed->markFailed('Planète introuvable', $now->modify('-1 hour'));
        $oldFailure = $event(BuildingCompletedHandler::TYPE, '2026-09-01 11:00:00');
        $oldFailure->markFailed('Planète introuvable', new \DateTimeImmutable('2026-09-01 11:00:00'));
        $done = $event(BuildingCompletedHandler::TYPE, '2026-10-06 10:00:00');
        $done->markDone($now);
        foreach ([...$pending, $failed, $oldFailure, $done] as $scheduled) {
            $entityManager->persist($scheduled);
        }
        $entityManager->flush();
        self::getContainer()->get('messenger.transport.failed')->send(new Envelope(new \stdClass()));

        $indicators = self::getContainer()->get(AdminIndicators::class)->current();

        self::assertSame(['Constructions en cours' => 2, 'fleet.arrival' => 1], $indicators->pendingEvents);
        self::assertSame(3, $indicators->pendingEventCount());
        self::assertSame(1, $indicators->lateEvents);
        self::assertSame(1, $indicators->failedEvents);
        self::assertSame(1, $indicators->failedMessages);
        self::assertTrue($indicators->hasAlerts());
    }

    public function testNoAlertsOnHealthyGame(): void
    {
        self::mockTime('2026-10-06 12:00:00');

        $indicators = self::getContainer()->get(AdminIndicators::class)->current();

        self::assertSame([], $indicators->pendingEvents);
        self::assertFalse($indicators->hasAlerts());
    }
}
