<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Fleet;

use App\Entity\BuildingType;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Fleet\ShipyardQueueFull;
use App\Exception\Research\MissingPrerequisites;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Repository\BuildingTypeRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\ShipyardOrderRepository;
use App\Repository\TechnologyRepository;
use App\Service\Fleet\ShipyardOrderCompletedHandler;
use App\Service\Fleet\ShipyardQueue;
use App\Service\Scheduling\ScheduledEventResolver;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Chantier spatial (§4.5) : commandes payées d'avance, postes en parallèle selon le niveau, livraison à l'inventaire
 * par les événements planifiés.
 */
final class ShipyardQueueTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testOrderPaysWholeBatchAndSchedulesDelivery(): void
    {
        $planet = $this->planet(shipyard: 1);

        $order = $this->queue()->order($planet, $this->ship('light_fighter'), 3);

        self::assertSame(3, $order->getQuantity());
        self::assertSame(0, $order->getSlot());
        self::assertEquals(new Resources(9000, 3000, 0), $order->getPaid());
        self::assertEquals(new Resources(91_000, 97_000, 100_000), $planet->getResources());
        // 3 × 2 880 s
        self::assertEquals(new \DateTimeImmutable('2026-10-06 10:00:00'), $order->getStartsAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-06 12:24:00'), $order->getEndsAt());
        $event = $order->getEvent();
        self::assertInstanceOf(ScheduledEvent::class, $event);
        self::assertSame(ShipyardOrderCompletedHandler::TYPE, $event->getType());
        self::assertSame(['order' => $order->getId(), 'ship' => 'light_fighter', 'quantity' => 3], $event->getPayload());
    }

    public function testOrdersQueueOnOneSlotAndRunInParallelOnSeveral(): void
    {
        $small = $this->planet(shipyard: 1);
        $first = $this->queue()->order($small, $this->ship('light_fighter'), 1);
        $second = $this->queue()->order($small, $this->ship('light_fighter'), 1);
        // Un seul poste : la deuxième commande attend la première
        self::assertEquals($first->getEndsAt(), $second->getStartsAt());

        $large = $this->planet(shipyard: 4);
        $first = $this->queue()->order($large, $this->ship('light_fighter'), 1);
        $second = $this->queue()->order($large, $this->ship('light_fighter'), 1);
        $third = $this->queue()->order($large, $this->ship('light_fighter'), 1);
        // Deux postes : deux commandes en parallèle, la troisième attend la première libérée
        self::assertSame([0, 1, 0], [$first->getSlot(), $second->getSlot(), $third->getSlot()]);
        self::assertEquals($first->getStartsAt(), $second->getStartsAt());
        self::assertEquals($first->getEndsAt(), $third->getStartsAt());
        self::assertSame(1152, $first->getDurationSeconds());
    }

    public function testDeliveryAddsShipsToPlanetAndNotifiesPlayer(): void
    {
        $planet = $this->planet(shipyard: 1);
        $order = $this->queue()->order($planet, $this->ship('light_fighter'), 2);
        $eventId = (int) $order->getEvent()?->getId();
        $this->clock->sleep(2 * 2880);

        self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve($eventId));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $planet = $entityManager->find(Planet::class, $planet->getId());
        \assert($planet instanceof Planet);
        self::assertSame(2, $planet->shipCount($this->ship('light_fighter')));
        self::assertSame([], self::getContainer()->get(ShipyardOrderRepository::class)->findForPlanet($planet));
        $updates = self::getContainer()->get(PublishedUpdates::class)->all();
        self::assertCount(1, $updates);
        self::assertStringContainsString('Chantier spatial : 2 × Chasseur léger livrés sur la planète mère', $updates[0]->getData());
    }

    public function testLockedShipIsRefused(): void
    {
        $planet = $this->planet(shipyard: 1);

        try {
            $this->queue()->order($planet, $this->ship('cruiser'), 1);
            self::fail('Le croiseur requiert le chantier 5.');
        } catch (MissingPrerequisites $exception) {
            self::assertStringContainsString('Chantier spatial niveau 5', MissingPrerequisites::describe($exception->missing));
        }
    }

    public function testInsufficientResourcesForTheWholeBatch(): void
    {
        $planet = $this->planet(shipyard: 1);

        $this->expectException(InsufficientResources::class);

        // Le lot entier est payé à la commande : 34 × 3 000 = 102 000 de métal pour 100 000 en stock
        $this->queue()->order($planet, $this->ship('light_fighter'), 34);
    }

    public function testQueueIsLimited(): void
    {
        $planet = $this->planet(shipyard: 1);
        for ($i = 0; $i < ShipyardQueue::MAX_ORDERS; ++$i) {
            $this->queue()->order($planet, $this->ship('light_fighter'), 1);
        }

        $this->expectException(ShipyardQueueFull::class);

        $this->queue()->order($planet, $this->ship('light_fighter'), 1);
    }

    /** Planète mère au chantier du niveau donné, propulsion à combustion 1, dépôts bien remplis */
    private function planet(int $shipyard): Planet
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $planet = $empire->getHomePlanet();
        $planet->setBuildingLevel($this->building('shipyard'), $shipyard);
        foreach (['metal_storage', 'crystal_storage', 'deuterium_storage'] as $storage) {
            $planet->setBuildingLevel($this->building($storage), 10);
        }
        $planet->storeResources(new Resources(100_000, 100_000, 100_000), $this->clock->now());
        $combustion = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('combustion_drive');
        \assert(null !== $combustion);
        $empire->setResearchLevel($combustion, 1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $planet;
    }

    private function building(string $code): BuildingType
    {
        $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }

    private function ship(string $code): ShipType
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }

    private function queue(): ShipyardQueue
    {
        return self::getContainer()->get(ShipyardQueue::class);
    }
}
