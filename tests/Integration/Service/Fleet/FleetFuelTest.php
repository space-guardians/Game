<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Fleet;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ShipType;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Fleet\FleetStatus;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\BuildingTypeRepository;
use App\Repository\FleetMovementRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Fleet\FleetAssembly;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Carburant (§4.6.3) : plein au départ, consommation par trajet, panne en route, ravitaillement.
 */
final class FleetFuelTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testFuelIsTakenFromPlanetAndBurntOnDeparture(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 10]);

        $movement = $this->dispatch()->dispatch($fleet, [$this->toTarget($empire)], 100, new Resources(), 4000);

        self::assertFalse($movement->isStranding());
        // 4 000 chargés sur la planète ; le trajet en a brûlé une partie
        self::assertEqualsWithDelta(50_000 - 4000, $empire->getHomePlanet()->getResources()->deuterium, 1.0);
        self::assertLessThan(4000, $fleet->getFuel());
        self::assertGreaterThan(0, $fleet->getFuel());
    }

    public function testTankCapacityLimitsTheFuelLoaded(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 1]);

        $this->expectExceptionObject(new InvalidFleetMission('Réservoirs trop petits : ils contiennent au plus 400 de deutérium.'));

        $this->dispatch()->dispatch($fleet, [$this->toTarget($empire)], 100, new Resources(), 401);
    }

    public function testEmptyTanksCannotLeave(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 1]);

        $this->expectExceptionObject(new InvalidFleetMission('Réservoirs vides : chargez du carburant pour partir.'));

        $this->dispatch()->dispatch($fleet, [$this->toTarget($empire)], 100, new Resources());
    }

    public function testRunningOutOfFuelStrandsTheFleetWhereTheTankEmpties(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 10]);
        $origin = $empire->getHomePlanet()->getSystem()->getPosition();

        $movement = $this->dispatch()->dispatch($fleet, [
            $this->toTarget($empire),
            new MissionStep(SpacePosition::planet($empire->getHomePlanet()), FleetAction::Station, 'retour'),
        ], 100, new Resources(), 1);

        self::assertTrue($movement->isStranding());
        self::assertLessThan(1.0, $movement->getReach());
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId());

        $fleet = $this->reload($fleet);
        self::assertSame(FleetStatus::Stranded, $fleet->getStatus());
        self::assertSame(0.0, $fleet->getFuel());
        self::assertNull($fleet->getPlanet());
        self::assertNull($fleet->getLocation()->systemId);
        // Immobilisée sur la route, à peu près à la part du trajet couverte (le transit domine la distance)
        $stranded = $fleet->getLocation()->toPosition()->global();
        self::assertGreaterThan(0.0, $stranded->distanceTo($origin));
        self::assertLessThan(5000.0, $stranded->distanceTo($origin));
        self::assertSame(FleetOrderStatus::Failed, $fleet->getOrders()[0]?->getStatus());
        self::assertStringContainsString('Panne de carburant', (string) $fleet->getOrders()[0]->getFailure());
        // Les ordres suivants attendent : pas de nouveau départ
        self::assertSame(FleetOrderStatus::Pending, $fleet->getOrders()[1]?->getStatus());
        self::assertNull(self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($fleet));
    }

    public function testRefuelDeliversCargoDeuteriumToTheStrandedFleet(): void
    {
        $empire = $this->empire();
        $stranded = $this->strandedFleet($empire);
        // Gestionnaire d'entités vidé au rechargement : on repart de l'empire géré
        $empire = $stranded->getEmpire();
        $rescuer = $this->fleet($empire, ['small_cargo' => 2]);

        $movement = $this->dispatch()->dispatch($rescuer, [
            new MissionStep($stranded->getLocation()->toPosition(), FleetAction::Refuel, 'flotte en panne', (int) $stranded->getId()),
        ], 100, new Resources(0, 0, 3000), 800);
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId());

        $stranded = $this->reload($stranded);
        // 3 000 en cargaison, réservoirs de 10 chasseurs : 4 000 → tout est livré
        self::assertSame(3000.0, $stranded->getFuel());
        self::assertSame(FleetStatus::Stationed, $stranded->getStatus());
        $rescuer = self::getContainer()->get(EntityManagerInterface::class)->find(Fleet::class, $rescuer->getId());
        \assert($rescuer instanceof Fleet);
        self::assertSame(0.0, $rescuer->getCargo()->deuterium);
        self::assertSame(FleetOrderStatus::Done, $rescuer->getOrders()[0]?->getStatus());
    }

    public function testOnlyAStrandedFleetCanBeRefuelled(): void
    {
        $empire = $this->empire();
        $target = $this->fleet($empire, ['light_fighter' => 1]);
        $rescuer = $this->fleet($empire, ['small_cargo' => 1]);

        $this->expectExceptionObject(new InvalidFleetMission('Ordre 1 : la flotte « Escadre » n’est pas en panne de carburant.'));

        $this->dispatch()->dispatch($rescuer, [new MissionStep($target->getLocation()->toPosition(), FleetAction::Refuel, 'cible', (int) $target->getId())], 100, new Resources(), 100);
    }

    public function testDisbandingReturnsFuelToThePlanet(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 1]);
        $fleet->refuel(300);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::getContainer()->get(FleetAssembly::class)->disband($fleet);

        self::assertEqualsWithDelta(50_000 + 300, $empire->getHomePlanet()->getResources()->deuterium, 1.0);
    }

    private function strandedFleet(Empire $empire): Fleet
    {
        $fleet = $this->fleet($empire, ['light_fighter' => 10]);
        $movement = $this->dispatch()->dispatch($fleet, [$this->toTarget($empire)], 100, new Resources(), 1);
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId());

        return $this->reload($fleet);
    }

    private function empire(): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $planet = $empire->getHomePlanet();
        foreach (['metal_storage', 'crystal_storage', 'deuterium_storage'] as $storage) {
            $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($storage);
            \assert(null !== $type);
            $planet->setBuildingLevel($type, 10);
        }
        $planet->storeResources(new Resources(50_000, 50_000, 50_000), $this->clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $empire;
    }

    /** Planète d'un système voisin, à 3 000 du système de départ */
    private function toTarget(Empire $empire): MissionStep
    {
        $home = $empire->getHomePlanet()->getSystem();
        $target = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 3000, $home->getPosition()->y),
        ])]);

        return new MissionStep(SpacePosition::planet($target), FleetAction::Station, 'cible');
    }

    /** @param array<string, int> $ships */
    private function fleet(Empire $empire, array $ships): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), $this->clock->now());
        foreach ($ships as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $fleet->addShips($type, $quantity);
        }
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }

    private function resolve(int $eventId): void
    {
        self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve($eventId));
    }

    private function reload(Fleet $fleet): Fleet
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $fleet = $entityManager->find(Fleet::class, $fleet->getId());
        \assert($fleet instanceof Fleet);

        return $fleet;
    }

    private function dispatch(): FleetDispatch
    {
        return self::getContainer()->get(FleetDispatch::class);
    }
}
