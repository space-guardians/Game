<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Research;

use App\Entity\BuildingType;
use App\Entity\Empire;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Entity\Technology;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Research\MissingPrerequisites;
use App\Exception\Research\ResearchInProgress;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Model\Economy\Resources;
use App\Repository\BuildingTypeRepository;
use App\Repository\ResearchQueueItemRepository;
use App\Repository\TechnologyRepository;
use App\Service\Research\ResearchCompletedHandler;
use App\Service\Research\ResearchQueue;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * File de recherche (§4.4) : une recherche par empire, lancée depuis n'importe quelle planète, vitesse selon la somme
 * des laboratoires, fin résolue par les événements planifiés.
 */
final class ResearchQueueTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testStartingPaysFromLaunchPlanetAndSchedulesCompletion(): void
    {
        $empire = $this->empireWithLaboratory();
        $planet = $empire->getHomePlanet();

        $item = $this->queue()->start($planet, $this->technology('energy'));

        self::assertSame(1, $item->getTargetLevel());
        self::assertSame($empire, $item->getEmpire());
        self::assertEquals(new Resources(0, 800, 400), $item->getPaid());
        self::assertEquals(new Resources(10_000, 9_200, 9_600), $planet->getResources());
        // 800 / (1 000 × (1 + 1)) h = 1 440 s
        self::assertEquals(new \DateTimeImmutable('2026-10-06 10:24:00'), $item->getEndsAt());
        $event = $item->getEvent();
        self::assertInstanceOf(ScheduledEvent::class, $event);
        self::assertSame(ResearchCompletedHandler::TYPE, $event->getType());
        self::assertSame(['item' => $item->getId(), 'technology' => 'energy', 'level' => 1], $event->getPayload());
    }

    public function testOnlyOneResearchPerEmpireWhateverThePlanet(): void
    {
        $empire = $this->empireWithLaboratory();
        $colony = $this->colonyOf($empire);
        $this->queue()->start($empire->getHomePlanet(), $this->technology('energy'));

        $this->expectException(ResearchInProgress::class);

        $this->queue()->start($colony, $this->technology('computer'));
    }

    public function testLaunchableFromColonyWithLaboratoriesSummedOverTheEmpire(): void
    {
        $empire = $this->empireWithLaboratory();
        $colony = $this->colonyOf($empire);
        $colony->setBuildingLevel($this->building('research_lab'), 2);
        $this->fill($colony);
        $this->flush();

        self::assertSame(3, $this->queue()->laboratoryLevels($empire));
        $item = $this->queue()->start($colony, $this->technology('energy'));

        self::assertSame($colony, $item->getPlanet());
        self::assertEquals(new Resources(10_000, 9_200, 9_600), $colony->getResources());
        // 800 / (1 000 × (1 + 3)) h = 720 s
        self::assertSame(720, $item->getDurationSeconds());
    }

    public function testLaboratoryPrerequisiteCountsTheWholeEmpire(): void
    {
        $empire = $this->empireWithLaboratory();
        $this->colonyOf($empire)->setBuildingLevel($this->building('research_lab'), 1);
        $this->flush();

        // Armure : laboratoire 2, atteint par 1 + 1 sur deux planètes
        $item = $this->queue()->start($empire->getHomePlanet(), $this->technology('armour'));

        self::assertSame('armour', $item->getTechnology()->getCode());
    }

    public function testLockedTechnologyIsRefused(): void
    {
        $empire = $this->empireWithLaboratory();

        try {
            $this->queue()->start($empire->getHomePlanet(), $this->technology('weapons'));
            self::fail('L’armement requiert le laboratoire 4.');
        } catch (MissingPrerequisites $exception) {
            self::assertSame('Laboratoire de recherche niveau 4', MissingPrerequisites::describe($exception->missing));
            self::assertNull($this->items()->findActiveFor($empire));
        }
    }

    public function testInsufficientResourcesOnLaunchPlanet(): void
    {
        $empire = $this->empireWithLaboratory();
        $colony = $this->colonyOf($empire);

        try {
            $this->queue()->start($colony, $this->technology('energy'));
            self::fail('La colonie n’a pas de quoi payer.');
        } catch (InsufficientResources) {
            self::assertNull($this->items()->findActiveFor($empire));
        }
    }

    public function testCompletionRaisesEmpireLevel(): void
    {
        $empire = $this->empireWithLaboratory();
        $item = $this->queue()->start($empire->getHomePlanet(), $this->technology('energy'));
        $eventId = (int) $item->getEvent()?->getId();
        $this->clock->sleep(1440);

        self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve($eventId));

        $empire = self::getContainer()->get(EntityManagerInterface::class)->find(Empire::class, $empire->getId());
        \assert($empire instanceof Empire);
        self::assertSame(1, $empire->researchLevel($this->technology('energy')));
        self::assertNull($this->items()->findActiveFor($empire));
    }

    /** Empire au laboratoire niveau 1 sur sa planète mère, aux dépôts bien remplis */
    private function empireWithLaboratory(): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $planet = $empire->getHomePlanet();
        $planet->setBuildingLevel($this->building('research_lab'), 1);
        $this->fill($planet);
        $this->flush();

        return $empire;
    }

    private function colonyOf(Empire $empire): Planet
    {
        $colony = PlanetFactory::createOne();
        $colony->assignTo($empire);
        $colony->storeResources(new Resources(), $this->clock->now());
        $this->flush();

        return $colony;
    }

    private function fill(Planet $planet): void
    {
        foreach (['metal_storage', 'crystal_storage', 'deuterium_storage'] as $storage) {
            $planet->setBuildingLevel($this->building($storage), 5);
        }
        $planet->storeResources(new Resources(10_000, 10_000, 10_000), $this->clock->now());
    }

    private function flush(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function building(string $code): BuildingType
    {
        $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }

    private function technology(string $code): Technology
    {
        $technology = self::getContainer()->get(TechnologyRepository::class)->findOneByCode($code);
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
