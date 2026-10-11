<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Combat;

use App\Entity\Debris;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\DebrisRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Action « Recyclage » (§4.6) : des recycleurs récupèrent un champ de débris (§4.7).
 */
final class RecyclingTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-11 10:00:00');
    }

    public function testRecyclersCollectTheWholeFieldWhenTheyCan(): void
    {
        [$empire, $planet] = $this->scene();
        $this->field($planet, new Resources(12_000, 4_000));
        $fleet = $this->fleet($empire, ['recycler' => 1]);

        $this->recycle($fleet, $planet);

        $fleet = $this->reload($fleet);
        self::assertSame(FleetOrderStatus::Done, $fleet->getOrders()[0]?->getStatus());
        self::assertEquals(new Resources(12_000, 4_000), $fleet->getCargo());
        // Champ vidé : il disparaît
        self::assertNull(self::getContainer()->get(DebrisRepository::class)->findAt(SpaceLocation::of(SpacePosition::planet($planet))));
    }

    public function testRecyclersCapacityLimitsTheHaul(): void
    {
        [$empire, $planet] = $this->scene();
        $this->field($planet, new Resources(30_000, 10_000));
        // Recycleur : 20 000 de cargo ; les chasseurs n'en récupèrent rien
        $fleet = $this->fleet($empire, ['recycler' => 1, 'light_fighter' => 10]);

        $this->recycle($fleet, $planet);

        self::assertEquals(new Resources(15_000, 5_000), $this->reload($fleet)->getCargo());
        $field = self::getContainer()->get(DebrisRepository::class)->findAt(SpaceLocation::of(SpacePosition::planet($planet)));
        self::assertEquals(new Resources(15_000, 5_000), $field?->getResources());
    }

    public function testNothingToRecycleFailsTheOrder(): void
    {
        [$empire, $planet] = $this->scene();
        $fleet = $this->fleet($empire, ['recycler' => 1]);

        $this->recycle($fleet, $planet);

        $order = $this->reload($fleet)->getOrders()[0];
        self::assertSame(FleetOrderStatus::Failed, $order?->getStatus());
        self::assertSame('Aucun champ de débris à cet endroit.', $order->getFailure());
    }

    public function testRecyclingNeedsARecycler(): void
    {
        [$empire, $planet] = $this->scene();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);

        $this->expectExceptionObject(new InvalidFleetMission('Ordre 1 : « Recyclage » demande au moins un recycleur dans la flotte.'));

        $this->recycle($fleet, $planet);
    }

    /** @return array{Empire, Planet} */
    private function scene(): array
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $home = $empire->getHomePlanet()->getSystem();
        $planet = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 3000, $home->getPosition()->y),
        ])]);

        return [$empire, $planet];
    }

    private function field(Planet $planet, Resources $resources): void
    {
        $debris = new Debris(SpaceLocation::of(SpacePosition::planet($planet)), (string) $planet->getAddress(), $this->clock->now());
        $debris->add($resources, $this->clock->now());
        $this->entityManager()->persist($debris);
        $this->entityManager()->flush();
    }

    private function recycle(Fleet $fleet, Planet $planet): void
    {
        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep(SpacePosition::planet($planet), FleetAction::Recycle, 'débris')], 100, new Resources());
        $this->clock->sleep($movement->getDurationSeconds());
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId());
    }

    /** @param array<string, int> $ships */
    private function fleet(Empire $empire, array $ships): Fleet
    {
        $fleet = new Fleet($empire, 'Récupérateurs', $empire->getHomePlanet(), $this->clock->now());
        foreach ($ships as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $fleet->addShips($type, $quantity);
        }
        $fleet->refuel($fleet->tankCapacity());
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();

        return $fleet;
    }

    private function reload(Fleet $fleet): Fleet
    {
        $this->entityManager()->clear();
        $fleet = $this->entityManager()->find(Fleet::class, $fleet->getId());
        \assert($fleet instanceof Fleet);

        return $fleet;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
