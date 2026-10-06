<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Research;

use App\Entity\BuildingType;
use App\Entity\Empire;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Entity\Technology;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Exception\Research\NoCancellableResearch;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Model\Economy\Resources;
use App\Repository\BuildingTypeRepository;
use App\Repository\ResearchQueueItemRepository;
use App\Repository\TechnologyRepository;
use App\Service\Economy\PlanetResources;
use App\Service\Research\ResearchQueue;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Annulation d'une recherche (§4.4) : remboursement au prorata du temps restant, sur la planète de lancement et dans
 * la limite de son stockage, comme pour les bâtiments.
 */
final class ResearchCancellationTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testRefundsRemainingShareToLaunchPlanetAndCancelsCompletion(): void
    {
        $empire = $this->empire();
        $colony = $this->colonyOf($empire);
        $item = $this->queue()->start($colony, $this->energy());
        $eventId = (int) $item->getEvent()?->getId();
        // Énergie : 800 cristal, 400 deutérium, 1 440 s avec un laboratoire ; annulée à mi-parcours
        $this->clock->sleep(720);
        $homeStock = $empire->getHomePlanet()->getResources();

        $result = $this->queue()->cancel($empire);

        self::assertEqualsWithDelta(0.5, $result->share, 1e-12);
        self::assertEquals(new Resources(0, 400, 200), $result->refunded);
        self::assertEquals(new Resources(), $result->lost);
        // Remboursée à la colonie qui a payé (plus sa production naturelle de 12 min), pas à la planète mère
        self::assertEqualsWithDelta(10_000 - 800 + 400, $colony->getResources()->crystal, 5.0);
        self::assertEquals($homeStock, $empire->getHomePlanet()->getResources());
        self::assertNull($this->items()->findActiveFor($empire));

        // Le réveil de fin n'a plus rien à faire : le niveau ne change pas
        $this->clock->sleep(1440);
        self::assertSame(0, self::getContainer()->get(ScheduledEventResolver::class)->resolve($eventId));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame(ScheduledEventStatus::Cancelled, $entityManager->find(ScheduledEvent::class, $eventId)?->getStatus());
        self::assertSame(0, $entityManager->find(Empire::class, $empire->getId())?->researchLevel($this->energy()));
    }

    public function testRefundBeyondStorageIsLost(): void
    {
        $empire = $this->empire();
        $planet = $empire->getHomePlanet();
        $this->queue()->start($planet, $this->energy());
        $this->clock->sleep(720);
        // Dépôts pleins : rien ne peut rentrer
        $capacity = self::getContainer()->get(PlanetResources::class)->snapshot($planet)->capacity;
        $planet->storeResources($capacity, $this->clock->now());

        $result = $this->queue()->cancel($empire);

        self::assertEquals(new Resources(), $result->refunded);
        self::assertEquals(new Resources(0, 400, 200), $result->lost);
        self::assertEquals($capacity, $planet->getResources());
    }

    public function testNothingToCancel(): void
    {
        $this->expectExceptionObject(NoCancellableResearch::none());

        $this->queue()->cancel($this->empire());
    }

    public function testFinishedResearchCannotBeCancelled(): void
    {
        $empire = $this->empire();
        $this->queue()->start($empire->getHomePlanet(), $this->energy());
        $this->clock->sleep(1440);

        $this->expectExceptionObject(NoCancellableResearch::finished());

        $this->queue()->cancel($empire);
    }

    /** Empire au laboratoire niveau 1, planète mère aux dépôts bien remplis */
    private function empire(): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $planet = $empire->getHomePlanet();
        $planet->setBuildingLevel($this->building('research_lab'), 1);
        $this->fill($planet);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $empire;
    }

    private function colonyOf(Empire $empire): Planet
    {
        $colony = PlanetFactory::createOne();
        $colony->assignTo($empire);
        $this->fill($colony);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $colony;
    }

    private function fill(Planet $planet): void
    {
        foreach (['metal_storage', 'crystal_storage', 'deuterium_storage'] as $storage) {
            $planet->setBuildingLevel($this->building($storage), 5);
        }
        $planet->storeResources(new Resources(10_000, 10_000, 10_000), $this->clock->now());
    }

    private function building(string $code): BuildingType
    {
        $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }

    private function energy(): Technology
    {
        $technology = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('energy');
        \assert(null !== $technology);

        return $technology;
    }

    private function items(): ResearchQueueItemRepository
    {
        return self::getContainer()->get(ResearchQueueItemRepository::class);
    }

    private function queue(): ResearchQueue
    {
        return self::getContainer()->get(ResearchQueue::class);
    }
}
