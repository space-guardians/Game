<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Event;

use App\Entity\Conversation;
use App\Entity\GameEvent;
use App\Entity\ScheduledEvent;
use App\Enum\Event\GameEventStatus;
use App\Factory\EmpireFactory;
use App\Repository\BuildingTypeRepository;
use App\Service\Economy\BuildingConstruction;
use App\Service\Economy\PlanetEconomy;
use App\Service\Event\GameEvents;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Événements de jeu (§4.16) : démarrage et arrêt par les événements planifiés, annonce aux joueurs, effets sur les
 * planètes couvertes, annulation et replanification.
 */
final class GameEventsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 10:00:00');
    }

    public function testAnEventStartsWithAnAnnouncementBoostsThenEnds(): void
    {
        $empire = EmpireFactory::createOne();
        $planet = $empire->getHomePlanet();
        $economy = self::getContainer()->get(PlanetEconomy::class);
        $construction = self::getContainer()->get(BuildingConstruction::class);
        $mine = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('metal_mine');
        \assert(null !== $mine);
        $baseRate = $economy->output($planet)->hourlyProduction->metal;
        $baseDuration = $construction->duration($planet, $mine, 1);
        $event = $this->event(productionBonus: 50, constructionBonus: 100);
        [$start, $end] = $this->wakeUps($event);

        $this->clock->sleep(3600);
        $this->resolve($start);

        self::assertSame(GameEventStatus::Running, $event->getStatus());
        $announcement = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Conversation::class)->findOneBy(['subject' => 'Événement : Pluie de météores']);
        self::assertNotNull($announcement);
        $message = $announcement->getMessages()->first();
        self::assertNotFalse($message);
        self::assertStringContainsString('production +50 %, construction +100 %', $message->getBody());
        self::assertEqualsWithDelta($baseRate * 1.5, $economy->output($planet)->hourlyProduction->metal, 0.01);
        self::assertSame((int) ceil($baseDuration / 2), $construction->duration($planet, $mine, 1));

        $this->clock->sleep(2 * 86400);
        $this->resolve($end);
        self::getContainer()->get(GameEvents::class)->reset();

        self::assertSame(GameEventStatus::Finished, $event->getStatus());
        self::assertEqualsWithDelta($baseRate, $economy->output($planet)->hourlyProduction->metal, 0.01);
    }

    public function testACancelledOrRescheduledEventIgnoresItsOldWakeUps(): void
    {
        $empire = EmpireFactory::createOne();
        $economy = self::getContainer()->get(PlanetEconomy::class);
        $baseRate = $economy->output($empire->getHomePlanet())->hourlyProduction->metal;
        $cancelled = $this->event(productionBonus: 50);
        [$start] = $this->wakeUps($cancelled);
        self::getContainer()->get(GameEvents::class)->cancel($cancelled);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $moved = $this->event(productionBonus: 50);
        [$oldStart] = $this->wakeUps($moved);
        $moved->setStartsAt($moved->getStartsAt()->modify('+1 day'));
        self::getContainer()->get(GameEvents::class)->schedule($moved);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->clock->sleep(3600);
        $this->resolve($start);
        $this->resolve($oldStart);

        self::assertSame(GameEventStatus::Cancelled, $cancelled->getStatus());
        self::assertSame(GameEventStatus::Scheduled, $moved->getStatus());
        self::assertEqualsWithDelta($baseRate, $economy->output($empire->getHomePlanet())->hourlyProduction->metal, 0.01);
    }

    private function event(int $productionBonus = 0, int $constructionBonus = 0): GameEvent
    {
        $event = new GameEvent($this->clock->now()->modify('+1 hour'), $this->clock->now()->modify('+2 days'));
        $event->setName('Pluie de météores');
        $event->setDescription('Des fragments riches en métal tombent sur l’univers.');
        $event->setProductionBonus($productionBonus);
        $event->setConstructionBonus($constructionBonus);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($event);
        $entityManager->flush();
        self::getContainer()->get(GameEvents::class)->schedule($event);
        $entityManager->flush();

        return $event;
    }

    /** @return list<int> réveils de début puis de fin de l'événement */
    private function wakeUps(GameEvent $event): array
    {
        $ids = [];
        foreach (self::getContainer()->get(EntityManagerInterface::class)->getRepository(ScheduledEvent::class)->findBy([], ['id' => 'ASC']) as $scheduled) {
            if (($scheduled->getPayload()['event'] ?? null) === $event->getId()) {
                $ids[] = (int) $scheduled->getId();
            }
        }

        return $ids;
    }

    private function resolve(int $id): void
    {
        self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve($id));
    }
}
