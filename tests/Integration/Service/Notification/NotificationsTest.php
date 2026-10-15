<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Notification;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Notification;
use App\Entity\ShipType;
use App\Enum\Fleet\FleetAction;
use App\Enum\Notification\NotificationType;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\BuildingTypeRepository;
use App\Repository\NotificationRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Economy\BuildingConstruction;
use App\Service\Fleet\FleetDispatch;
use App\Service\Messaging\Messaging;
use App\Service\Notification\Notifications;
use App\Service\Scheduling\ScheduledEventResolver;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mercure\Update;
use Zenstruck\Foundry\Test\Factories;

/**
 * Notifications (§4.12.3) : fin de construction, arrivée de flotte, attaque entrante, nouveau message ; enregistrées
 * avec le traitement, poussées en direct (badge des non lues) une fois celui-ci validé.
 */
final class NotificationsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 10:00:00');
    }

    public function testBuildingCompletionIsNotifiedAndPushedWithTheUnreadCount(): void
    {
        $empire = EmpireFactory::createOne();
        $mine = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('metal_mine');
        \assert(null !== $mine);
        $item = self::getContainer()->get(BuildingConstruction::class)->start($empire->getHomePlanet(), $mine);
        $this->clock->sleep(120);

        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $item->getEvent()?->getId());

        $notifications = $this->notificationsOf($empire);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationType::BuildingCompleted, $notifications[0]->getType());
        self::assertSame(\sprintf('Mine de métal niveau 1 achevé sur %s.', $empire->getHomePlanet()), $notifications[0]->getText());
        self::assertSame('/batiments', $notifications[0]->getLink());
        $stream = $this->lastUpdateContaining('sg-unread-notifications');
        self::assertSame(['/empire/' . $empire->getId()], $stream->getTopics());
        self::assertMatchesRegularExpression('/target="sg-unread-notifications">\s*<template>1<\/template>/', $stream->getData());
        self::assertStringContainsString('target="notifications-list"', $stream->getData());
    }

    public function testFleetArrivalAndIncomingAttackAreNotified(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $home = $ana->getHomePlanet();
        $bob = EmpireFactory::createOne(['name' => 'Bob', 'homePlanet' => PlanetFactory::new(['system' => StarSystemFactory::new(['galaxy' => $home->getSystem()->getGalaxy(), 'position' => new GlobalPosition($home->getSystem()->getPosition()->x + 900, $home->getSystem()->getPosition()->y)])])]);
        $fleet = $this->fleet($ana);

        // Départ en attaque : le défenseur est prévenu aussitôt
        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep(SpacePosition::planet($bob->getHomePlanet()), FleetAction::JoinBattle, 'Bob')], 100, new Resources());
        $attack = $this->notificationsOf($bob);
        self::assertCount(1, $attack);
        self::assertSame(NotificationType::IncomingAttack, $attack[0]->getType());
        self::assertSame(\sprintf('Attaque entrante sur %s : Ana arrive à %s.', $bob->getHomePlanet(), $movement->getArrivesAt()->format('H:i')), $attack[0]->getText());

        // Une flotte qui stationne dans son propre système : arrivée notifiée à son empire
        $scout = $this->fleet($ana);
        $trip = self::getContainer()->get(FleetDispatch::class)->dispatch($scout, [new MissionStep(SpacePosition::system($home->getSystem()), FleetAction::Station, 'système')], 100, new Resources());
        $this->clock->sleep($trip->getDurationSeconds());
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $trip->getEvent()?->getId());
        $arrivals = array_values(array_filter($this->notificationsOf($ana), static fn(Notification $notification): bool => NotificationType::FleetArrived === $notification->getType()));
        self::assertCount(1, $arrivals);
        self::assertSame('Flotte « Escadre » arrivée : système (Stationner).', $arrivals[0]->getText());
    }

    public function testANewMessageIsNotifiedToItsRecipientsOnly(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);

        $conversation = self::getContainer()->get(Messaging::class)->start($ana, ['Bob'], 'Alliance ?', 'Bonjour Bob');

        self::assertSame([], $this->notificationsOf($ana));
        $received = $this->notificationsOf($bob);
        self::assertCount(1, $received);
        self::assertSame('Nouveau message de Ana : Alliance ?', $received[0]->getText());
        self::assertSame('/messagerie/' . $conversation->getId(), $received[0]->getLink());
    }

    public function testDiscardedNotificationsAreNeverPushed(): void
    {
        $empire = EmpireFactory::createOne();
        $notifications = self::getContainer()->get(Notifications::class);
        $notifications->notify($empire, NotificationType::FleetArrived, 'Rien à voir');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $notifications->discard();
        $notifications->publish();

        self::assertSame([], array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => str_contains($update->getData(), 'Rien à voir')));
        self::assertSame(1, $notifications->unreadCount($empire));
        $notifications->markAllRead($empire);
        self::assertSame(0, $notifications->unreadCount($empire));
    }

    /** @return list<Notification> */
    private function notificationsOf(Empire $empire): array
    {
        return self::getContainer()->get(NotificationRepository::class)->findPage($empire, 0, 50);
    }

    private function lastUpdateContaining(string $text): Update
    {
        $updates = array_values(array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => str_contains($update->getData(), $text)));
        self::assertNotEmpty($updates);

        return $updates[\count($updates) - 1];
    }

    private function fleet(Empire $empire): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), $this->clock->now());
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('light_fighter');
        \assert($type instanceof ShipType);
        $fleet->addShips($type, 3);
        $fleet->refuel($fleet->tankCapacity());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }
}
