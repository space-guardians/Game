<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Economy;

use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Message\ConsolidateResources;
use App\MessageHandler\ConsolidateResourcesHandler;
use App\Model\Economy\Resources;
use App\Repository\PlanetRepository;
use App\Service\Economy\PlanetResources;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Ressources calculées à la demande et consolidation périodique (§4.2).
 */
final class PlanetResourcesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testComputesCurrentStockWithoutWriting(): void
    {
        $planet = EmpireFactory::createOne(['foundedAt' => $this->clock->now()])->getHomePlanet();
        $this->clock->sleep(2 * 3600);

        $snapshot = $this->service()->snapshot($planet);

        // Production de base : 30 métal et 15 cristal par heure
        self::assertEquals(new Resources(560, 530, 0), $snapshot->amounts);
        self::assertEquals(new Resources(30, 15, 0), $snapshot->hourlyProduction);
        self::assertEquals(new Resources(10_000, 10_000, 10_000), $snapshot->capacity);
        self::assertEquals(new Resources(500, 500, 0), $planet->getResources(), 'Lecture seule');
    }

    public function testSettleStoresStockAtCurrentSecond(): void
    {
        $planet = EmpireFactory::createOne(['foundedAt' => $this->clock->now()])->getHomePlanet();
        $this->clock->sleep(3600.75);

        $this->service()->settle($planet);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertEquals(new \DateTimeImmutable('2026-10-06 11:00:00'), $planet->getResourcesUpdatedAt());
        self::assertEquals(new Resources(530, 515, 0), $planet->getResources());
        // Une consolidation suivante ne recompte pas la fraction de seconde
        $this->clock->sleep(0.2);
        self::assertEquals(new Resources(530, 515, 0), $this->service()->snapshot($planet)->amounts);
    }

    public function testProductionStopsAtStorageCapacity(): void
    {
        $planet = EmpireFactory::createOne(['foundedAt' => $this->clock->now()])->getHomePlanet();
        $this->clock->sleep(30 * 24 * 3600);

        self::assertEquals(new Resources(10_000, 10_000, 0), $this->service()->snapshot($planet)->amounts);
    }

    public function testFreePlanetDoesNotProduce(): void
    {
        $planet = PlanetFactory::createOne();
        $this->clock->sleep(3600);

        self::assertEquals(Resources::zero(), $this->service()->snapshot($planet)->amounts);
    }

    public function testConsolidationWritesOnlyStaleInhabitedPlanets(): void
    {
        $stale = EmpireFactory::createOne(['foundedAt' => $this->clock->now()])->getHomePlanet();
        $this->clock->sleep(2 * 3600);
        $recent = EmpireFactory::createOne(['foundedAt' => $this->clock->now()->modify('-30 minutes')])->getHomePlanet();
        $free = PlanetFactory::createOne();

        self::getContainer()->get(ConsolidateResourcesHandler::class)(new ConsolidateResources());

        $planets = self::getContainer()->get(PlanetRepository::class);
        self::assertEquals(new Resources(560, 530, 0), $planets->find($stale->getId())?->getResources());
        self::assertEquals($this->clock->now(), $planets->find($stale->getId())?->getResourcesUpdatedAt());
        self::assertEquals(new Resources(500, 500, 0), $planets->find($recent->getId())?->getResources());
        self::assertNull($planets->find($free->getId())?->getResourcesUpdatedAt());
    }

    private function service(): PlanetResources
    {
        return self::getContainer()->get(PlanetResources::class);
    }
}
