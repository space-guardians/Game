<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Economy;

use App\Entity\BuildingType;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Exception\Economy\ConstructionInProgress;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Research\MissingPrerequisites;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Repository\BuildingQueueItemRepository;
use App\Repository\BuildingTypeRepository;
use App\Repository\PlanetRepository;
use App\Repository\TechnologyRepository;
use App\Service\Economy\BuildingCompletedHandler;
use App\Service\Economy\BuildingConstruction;
use App\Service\Economy\PlanetResources;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * File de construction (§4.3) : lancement, une construction à la fois, fin résolue par les événements planifiés.
 */
final class BuildingConstructionTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testStartingPaysCostAndSchedulesCompletion(): void
    {
        $planet = $this->homePlanet();

        $item = $this->construction()->start($planet, $this->type('metal_mine'));

        self::assertSame(1, $item->getTargetLevel());
        self::assertEquals(new Resources(60, 15), $item->getPaid());
        self::assertEquals(new Resources(440, 485, 0), $planet->getResources());
        // 75 / 2 500 h = 108 s
        self::assertEquals(new \DateTimeImmutable('2026-10-06 10:01:48'), $item->getEndsAt());
        $event = $item->getEvent();
        self::assertInstanceOf(ScheduledEvent::class, $event);
        self::assertSame(BuildingCompletedHandler::TYPE, $event->getType());
        self::assertEquals($item->getEndsAt(), $event->getDueAt());
        self::assertSame(['item' => $item->getId(), 'building' => 'metal_mine', 'level' => 1], $event->getPayload());
    }

    public function testOnlyOneConstructionAtATime(): void
    {
        $planet = $this->homePlanet();
        $this->construction()->start($planet, $this->type('metal_mine'));

        $this->expectException(ConstructionInProgress::class);

        $this->construction()->start($planet, $this->type('crystal_mine'));
    }

    public function testRefusesWhenResourcesAreInsufficient(): void
    {
        $planet = $this->homePlanet();

        try {
            $this->construction()->start($planet, $this->type('robot_factory'));
            self::fail('L’usine de robots coûte plus de deutérium que la dotation de départ.');
        } catch (InsufficientResources $exception) {
            self::assertEquals(new Resources(0, 0, 200), $exception->missing);
            self::assertNull(self::getContainer()->get(BuildingQueueItemRepository::class)->findActiveFor($planet));
        }
    }

    public function testRefusesLockedBuilding(): void
    {
        $planet = $this->homePlanet();

        try {
            $this->construction()->start($planet, $this->type('fusion_reactor'));
            self::fail('La centrale à fusion requiert le synthétiseur de deutérium 5 et l’énergie 3.');
        } catch (MissingPrerequisites $exception) {
            self::assertSame('Synthétiseur de deutérium niveau 5, Énergie niveau 3', MissingPrerequisites::describe($exception->missing));
            self::assertNull(self::getContainer()->get(BuildingQueueItemRepository::class)->findActiveFor($planet));
        }
    }

    public function testUnlockedBuildingOnceItsPrerequisitesAreMet(): void
    {
        $planet = $this->homePlanet();
        $planet->setBuildingLevel($this->type('deuterium_synthesizer'), 5);
        $owner = $planet->getOwner();
        \assert(null !== $owner);
        $owner->setResearchLevel(self::getContainer()->get(TechnologyRepository::class)->findOneByCode('energy') ?? throw new \LogicException(), 3);
        $planet->storeResources(new Resources(10_000, 10_000, 10_000), self::getContainer()->get(ClockInterface::class)->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $item = $this->construction()->start($planet, $this->type('fusion_reactor'));

        self::assertSame(1, $item->getTargetLevel());
    }

    public function testCompletionRaisesLevelAtEndTime(): void
    {
        $planet = $this->homePlanet();
        $item = $this->construction()->start($planet, $this->type('metal_mine'));
        $eventId = (int) $item->getEvent()?->getId();
        // Résolu 10 minutes après la fin (worker arrêté) : la production change quand même à l'heure de fin
        $this->clock->sleep(108 + 600);

        self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve($eventId));

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = self::getContainer()->get(PlanetRepository::class)->find($planet->getId());
        \assert($reloaded instanceof Planet);
        self::assertSame(1, $reloaded->buildingLevel($this->type('metal_mine')));
        self::assertNull(self::getContainer()->get(BuildingQueueItemRepository::class)->findActiveFor($reloaded));
        self::assertSame(ScheduledEventStatus::Done, self::getContainer()->get(EntityManagerInterface::class)->find(ScheduledEvent::class, $eventId)?->getStatus());
        // Production naturelle seule pendant la construction (pas d'énergie pour la nouvelle mine ensuite)
        self::assertEquals(new \DateTimeImmutable('2026-10-06 10:01:48'), $reloaded->getResourcesUpdatedAt());
        self::assertEqualsWithDelta(440 + 30 * 108 / 3600, $reloaded->getResources()->metal, 1e-9);
        self::assertEqualsWithDelta(440 + 30 * 708 / 3600, self::getContainer()->get(PlanetResources::class)->snapshot($reloaded)->amounts->metal, 1e-9);
    }

    public function testFactoriesShortenConstruction(): void
    {
        $planet = $this->homePlanet();
        $planet->setBuildingLevel($this->type('robot_factory'), 1);
        $planet->setBuildingLevel($this->type('nanite_factory'), 1);

        $item = $this->construction()->start($planet, $this->type('metal_mine'));

        self::assertSame(27, $item->getDurationSeconds());
    }

    private function homePlanet(): Planet
    {
        return EmpireFactory::createOne(['foundedAt' => $this->clock->now()])->getHomePlanet();
    }

    private function type(string $code): BuildingType
    {
        $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }

    private function construction(): BuildingConstruction
    {
        return self::getContainer()->get(BuildingConstruction::class);
    }
}
