<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Scheduling;

use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Message\ResolveDueEvents;
use App\Message\ResolveScheduledEvent;
use App\MessageHandler\ResolveDueEventsHandler;
use App\Repository\ScheduledEventRepository;
use App\Service\Scheduling\EventScheduler;
use App\Service\Scheduling\ScheduledEventResolver;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use App\Tests\Fixtures\Scheduling\RecordingEventHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * Socle des événements planifiés (§5.2) : planification, réveil, résolution sous verrou, publication Mercure.
 */
final class ScheduledEventResolverTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testSchedulingStoresEventAndSendsDelayedWakeUp(): void
    {
        $planet = PlanetFactory::createOne();

        $event = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+2 hours 14 minutes'), $planet, ['niveau' => 3]);

        self::assertNotNull($event->getId());
        self::assertSame(ScheduledEventStatus::Pending, $event->getStatus());
        self::assertSame(['niveau' => 3], $event->getPayload());
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new ResolveScheduledEvent((int) $event->getId()), $sent[0]->getMessage());
        self::assertSame((2 * 3600 + 14 * 60) * 1000, $sent[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testEarlyWakeUpIsPostponedToDueTime(): void
    {
        $event = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+10 minutes'), PlanetFactory::createOne());
        $this->clock->sleep(9 * 60);

        self::assertSame(0, $this->resolver()->resolve((int) $event->getId()));

        self::assertSame([], $this->recorder()->handled);
        $wakeUps = $this->transport()->getSent();
        self::assertCount(2, $wakeUps);
        self::assertSame(60_000, $wakeUps[1]->last(DelayStamp::class)?->getDelay());
    }

    public function testResolvesDueEventsOfPlanetInDueOrderAndPublishesThem(): void
    {
        $empire = EmpireFactory::createOne();
        $planet = $empire->getHomePlanet();
        $temperature = $planet->getTemperature();
        $late = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+5 minutes'), $planet);
        $early = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+3 minutes'), $planet);
        $future = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+1 hour'), $planet);
        $this->clock->sleep(6 * 60);

        // Le réveil du plus tardif résout aussi le plus ancien, dans l'ordre des échéances
        self::assertSame(2, $this->resolver()->resolve((int) $late->getId()));

        self::assertSame([$early->getId(), $late->getId()], $this->recorder()->handled);
        self::assertSame(ScheduledEventStatus::Done, $this->reload($early)->getStatus());
        self::assertEquals($this->clock->now(), $this->reload($late)->getResolvedAt());
        self::assertSame(ScheduledEventStatus::Pending, $this->reload($future)->getStatus());
        self::assertSame($temperature + 2, $this->temperature($planet));

        $updates = self::getContainer()->get(PublishedUpdates::class)->all();
        self::assertCount(2, $updates);
        self::assertSame(['/planet/' . $planet->getId(), '/empire/' . $empire->getId()], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
        self::assertSame(['event' => $early->getId(), 'type' => 'test.record', 'status' => 'done'], json_decode($updates[0]->getData(), true));
    }

    public function testNeverResolvesTwice(): void
    {
        $event = $this->scheduler()->schedule('test.record', $this->clock->now(), PlanetFactory::createOne());

        self::assertSame(1, $this->resolver()->resolve((int) $event->getId()));
        self::assertSame(0, $this->resolver()->resolve((int) $event->getId()));

        self::assertSame([$event->getId()], $this->recorder()->handled);
    }

    public function testFailureRollsBackHandlerChangesAndDoesNotBlockNextEvents(): void
    {
        $planet = PlanetFactory::createOne();
        $temperature = $planet->getTemperature();
        $failing = $this->scheduler()->schedule('test.fail', $this->clock->now()->modify('+1 minute'), $planet);
        $next = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+2 minutes'), $planet);
        $this->clock->sleep(3 * 60);

        self::assertSame(2, $this->resolver()->resolve((int) $failing->getId()));

        $failed = $this->reload($failing);
        self::assertSame(ScheduledEventStatus::Failed, $failed->getStatus());
        self::assertSame('Résolution impossible.', $failed->getError());
        self::assertSame(1, $failed->getAttempts());
        self::assertSame(ScheduledEventStatus::Done, $this->reload($next)->getStatus());
        // + 100 du gestionnaire en échec annulé, + 1 du suivant conservé
        self::assertSame($temperature + 1, $this->temperature($planet));
    }

    public function testUnknownTypeIsMarkedFailed(): void
    {
        $event = $this->scheduler()->schedule('type.inconnu', $this->clock->now(), PlanetFactory::createOne());

        $this->resolver()->resolve((int) $event->getId());

        self::assertSame('Aucun gestionnaire pour les événements « type.inconnu ».', $this->reload($event)->getError());
    }

    public function testCancelledEventIsIgnored(): void
    {
        $event = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+1 minute'), PlanetFactory::createOne());
        $event->cancel($this->clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->clock->sleep(120);

        self::assertSame(0, $this->resolver()->resolve((int) $event->getId()));
        self::assertSame([], $this->recorder()->handled);
    }

    public function testPeriodicCheckResolvesEventsWhoseWakeUpWasLost(): void
    {
        $first = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+1 minute'), PlanetFactory::createOne());
        $second = $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+2 minutes'));
        $this->scheduler()->schedule('test.record', $this->clock->now()->modify('+1 day'));
        $this->transport()->reset();
        $this->clock->sleep(5 * 60);

        self::getContainer()->get(ResolveDueEventsHandler::class)(new ResolveDueEvents());

        self::assertSame([$first->getId(), $second->getId()], $this->recorder()->handled);
        self::assertSame([], self::getContainer()->get(ScheduledEventRepository::class)->findDueIds($this->clock->now(), 10));
    }

    private function scheduler(): EventScheduler
    {
        return self::getContainer()->get(EventScheduler::class);
    }

    private function resolver(): ScheduledEventResolver
    {
        return self::getContainer()->get(ScheduledEventResolver::class);
    }

    private function recorder(): RecordingEventHandler
    {
        return self::getContainer()->get(RecordingEventHandler::class);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function reload(ScheduledEvent $event): ScheduledEvent
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = self::getContainer()->get(ScheduledEventRepository::class)->find($event->getId());
        \assert($reloaded instanceof ScheduledEvent);

        return $reloaded;
    }

    private function temperature(Planet $planet): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT temperature FROM planet WHERE id = ?', [$planet->getId()]);
    }
}
