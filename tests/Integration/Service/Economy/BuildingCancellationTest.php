<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Economy;

use App\Entity\BuildingType;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Exception\Economy\NoCancellableConstruction;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Repository\BuildingQueueItemRepository;
use App\Repository\BuildingTypeRepository;
use App\Service\Economy\BuildingConstruction;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Annulation d'une construction (§4.3) : remboursement au prorata du temps restant, plafonné par le stockage.
 */
final class BuildingCancellationTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testRefundsRemainingShareAndCancelsCompletion(): void
    {
        $planet = $this->homePlanet();
        $item = $this->construction()->start($planet, $this->type('crystal_mine'));
        $eventId = (int) $item->getEvent()?->getId();
        // Mine de cristal : 48 métal, 24 cristal, 104 s ; annulée à mi-parcours
        $this->clock->sleep(52);

        $result = $this->construction()->cancel($planet);

        self::assertEqualsWithDelta(0.5, $result->share, 1e-12);
        self::assertEquals(new Resources(24, 12), $result->refunded);
        self::assertEquals(new Resources(), $result->lost);
        self::assertEqualsWithDelta(500 - 48 + 24 + 30 * 52 / 3600, $planet->getResources()->metal, 1e-9);
        self::assertNull($this->queue()->findActiveFor($planet));

        // Le réveil de fin n'a plus rien à faire : le niveau ne change pas
        $this->clock->sleep(120);
        self::assertSame(0, self::getContainer()->get(ScheduledEventResolver::class)->resolve($eventId));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(ScheduledEventStatus::Cancelled, self::getContainer()->get(EntityManagerInterface::class)->find(ScheduledEvent::class, $eventId)?->getStatus());
    }

    public function testExcessBeyondStorageIsLost(): void
    {
        $planet = $this->homePlanet();
        $this->construction()->start($planet, $this->type('metal_mine'));
        // Les dépôts se sont remplis pendant la construction (production, butin…)
        $planet->storeResources(new Resources(9_990, 500, 0), $this->clock->now());

        $result = $this->construction()->cancel($planet);

        self::assertEquals(new Resources(10, 15), $result->refunded);
        self::assertEquals(new Resources(50), $result->lost);
        self::assertEquals(new Resources(10_000, 515, 0), $planet->getResources());
    }

    public function testNothingToCancel(): void
    {
        $this->expectException(NoCancellableConstruction::class);

        $this->construction()->cancel($this->homePlanet());
    }

    public function testFinishedConstructionCannotBeCancelled(): void
    {
        $planet = $this->homePlanet();
        $this->construction()->start($planet, $this->type('metal_mine'));
        // Échue mais pas encore résolue par le worker
        $this->clock->sleep(200);

        $this->expectExceptionObject(NoCancellableConstruction::finished());

        $this->construction()->cancel($planet);
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

    private function queue(): BuildingQueueItemRepository
    {
        return self::getContainer()->get(BuildingQueueItemRepository::class);
    }
}
