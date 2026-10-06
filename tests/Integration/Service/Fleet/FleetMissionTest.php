<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Fleet;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
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
use App\Repository\FleetMovementRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Fleet\FleetArrivalHandler;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Suite d'ordres de flotte (§4.6) : envoi, enchaînement « se déplacer puis agir », transport de ressources, pas de
 * retour implicite.
 */
final class FleetMissionTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testTransportThenReturnRunsOrdersOneAfterAnother(): void
    {
        $empire = $this->empire();
        $home = $empire->getHomePlanet();
        $colony = $this->colonyOf($empire);
        $fleet = $this->fleet($empire, ['small_cargo' => 2]);
        $colonyStock = $colony->getResources();

        $first = $this->dispatch()->dispatch($fleet, [
            new MissionStep(SpacePosition::planet($colony), FleetAction::Transport, 'colonie'),
            new MissionStep(SpacePosition::planet($home), FleetAction::Station, 'retour'),
        ], 100, new Resources(3000, 2000, 1000));

        // Départ : cargaison prélevée sur la planète mère, flotte en vol vers le premier ordre
        self::assertSame(FleetStatus::InFlight, $fleet->getStatus());
        self::assertNull($fleet->getPlanet());
        self::assertEquals(new Resources(3000, 2000, 1000), $fleet->getCargo());
        self::assertEquals(new Resources(7000, 8000, 9000), $home->getResources());
        self::assertSame(FleetOrderStatus::InProgress, $fleet->getOrders()[0]?->getStatus());
        self::assertSame(FleetArrivalHandler::TYPE, $first->getEvent()?->getType());
        self::assertSame($colony->getId(), $first->getEvent()->getPlanet()?->getId());

        // Arrivée à la colonie : déchargement, puis départ aussitôt vers l'ordre suivant
        $this->clock->sleep($first->getDurationSeconds());
        $this->resolve((int) $first->getEvent()->getId());
        $fleet = $this->reload($fleet);
        $colony = $this->reloadPlanet($colony);
        self::assertEqualsWithDelta($colonyStock->metal + 3000, $colony->getResources()->metal, 50.0);
        self::assertEquals(new Resources(), $fleet->getCargo());
        self::assertSame(FleetOrderStatus::Done, $fleet->getOrders()[0]?->getStatus());
        self::assertSame(FleetStatus::InFlight, $fleet->getStatus());
        $second = self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($fleet);
        self::assertNotNull($second);
        self::assertEquals($first->getArrivesAt(), $second->getDepartedAt());
        self::assertSame($colony->getId(), $second->getOrigin()->planetId);

        // Retour : stationnée sur la planète mère, carnet terminé
        $this->clock->sleep($second->getDurationSeconds());
        $this->resolve((int) $second->getEvent()?->getId());
        $fleet = $this->reload($fleet);
        self::assertSame(FleetStatus::Stationed, $fleet->getStatus());
        self::assertSame($home->getId(), $fleet->getPlanet()?->getId());
        self::assertTrue($fleet->isAtHome());
        self::assertSame([FleetOrderStatus::Done, FleetOrderStatus::Done], array_map(static fn($order): FleetOrderStatus => $order->getStatus(), $fleet->getOrders()->toArray()));
        self::assertNull(self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($fleet));
    }

    public function testNoImplicitReturnAfterLastOrder(): void
    {
        $empire = $this->empire();
        $colony = $this->colonyOf($empire);
        $fleet = $this->fleet($empire, ['light_fighter' => 3]);

        $movement = $this->dispatch()->dispatch($fleet, [new MissionStep(SpacePosition::system($colony->getSystem()), FleetAction::Station, 'système')], 50, new Resources());
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId());

        // Stationnée au niveau du système cible, sans planète ni retour
        $fleet = $this->reload($fleet);
        self::assertSame(FleetStatus::Stationed, $fleet->getStatus());
        self::assertNull($fleet->getPlanet());
        self::assertSame($colony->getSystem()->getId(), $fleet->getLocation()->systemId);
        self::assertNull($fleet->getLocation()->localX);
        self::assertFalse($fleet->isAtHome());
    }

    public function testColonizingAFreePlanetConsumesOneColonyShip(): void
    {
        $empire = $this->empire();
        $target = $this->freePlanetNear($empire);
        $fleet = $this->fleet($empire, ['colony_ship' => 2, 'light_fighter' => 3]);

        $movement = $this->dispatch()->dispatch($fleet, [
            new MissionStep(SpacePosition::planet($target), FleetAction::Colonize, 'cible'),
            new MissionStep(SpacePosition::planet($empire->getHomePlanet()), FleetAction::Station, 'retour'),
        ], 100, new Resources(1000, 500));
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId());

        $target = $this->reloadPlanet($target);
        self::assertSame($empire->getId(), $target->getOwner()?->getId());
        // Cargaison déposée sur la colonie, dont la production démarre à la fondation
        self::assertEquals(new Resources(1000, 500), $target->getResources());
        self::assertEquals($movement->getArrivesAt(), $target->getResourcesUpdatedAt());
        $fleet = $this->reload($fleet);
        self::assertSame(1, $fleet->shipCount($this->ship('colony_ship')));
        self::assertSame(3, $fleet->shipCount($this->ship('light_fighter')));
        self::assertSame(FleetOrderStatus::Done, $fleet->getOrders()[0]?->getStatus());
        // Le reste de la flotte poursuit son carnet
        self::assertSame(FleetStatus::InFlight, $fleet->getStatus());
    }

    public function testFleetMadeOfOneColonyShipDisappears(): void
    {
        $empire = $this->empire();
        $target = $this->freePlanetNear($empire);
        $fleet = $this->fleet($empire, ['colony_ship' => 1]);
        $fleetId = $fleet->getId();

        $movement = $this->dispatch()->dispatch($fleet, [
            new MissionStep(SpacePosition::planet($target), FleetAction::Colonize, 'cible'),
            new MissionStep(SpacePosition::planet($empire->getHomePlanet()), FleetAction::Station, 'retour'),
        ], 100, new Resources());
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->find(Fleet::class, $fleetId));
        self::assertSame($empire->getId(), $this->reloadPlanet($target)->getOwner()?->getId());
    }

    public function testOccupiedPlanetCannotBeColonized(): void
    {
        $empire = $this->empire();
        $occupied = $this->freePlanetNear($empire);
        $occupied->assignTo(EmpireFactory::createOne());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $fleet = $this->fleet($empire, ['colony_ship' => 1]);

        $movement = $this->dispatch()->dispatch($fleet, [new MissionStep(SpacePosition::planet($occupied), FleetAction::Colonize, 'cible')], 100, new Resources());
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId());

        $fleet = $this->reload($fleet);
        $order = $fleet->getOrders()[0];
        self::assertSame(FleetOrderStatus::Failed, $order?->getStatus());
        self::assertStringContainsString('est déjà occupée', (string) $order->getFailure());
        self::assertSame(1, $fleet->shipCount($this->ship('colony_ship')));
        self::assertSame(FleetStatus::Stationed, $fleet->getStatus());
    }

    public function testColonizingNeedsAColonyShipPerOrder(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 3]);

        $this->expectExceptionObject(new InvalidFleetMission('« Coloniser » consomme un colonisateur : il en faut 1 dans la flotte.'));

        $this->dispatch()->dispatch($fleet, [new MissionStep(SpacePosition::planet($this->freePlanetNear($empire)), FleetAction::Colonize, 'cible')], 100, new Resources());
    }

    public function testTransportMustTargetAPlanet(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);

        $this->expectExceptionObject(new InvalidFleetMission('Ordre 1 : « Transport de ressources » doit viser une planète.'));

        $this->dispatch()->dispatch($fleet, [new MissionStep(SpacePosition::system($empire->getHomePlanet()->getSystem()), FleetAction::Transport, 'système')], 100, new Resources());
    }

    public function testCargoIsLimitedByCapacity(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);

        $this->expectExceptionObject(new InvalidFleetMission('Cargaison trop lourde : la flotte emporte au plus 5000.'));

        $this->dispatch()->dispatch($fleet, [new MissionStep(SpacePosition::planet($this->colonyOf($empire)), FleetAction::Transport, 'colonie')], 100, new Resources(4000, 2000));
    }

    public function testFleetInFlightCannotBeSentAgain(): void
    {
        $empire = $this->empire();
        $colony = $this->colonyOf($empire);
        $fleet = $this->fleet($empire, ['light_fighter' => 1]);
        $step = new MissionStep(SpacePosition::planet($colony), FleetAction::Station, 'colonie');
        $this->dispatch()->dispatch($fleet, [$step], 100, new Resources());

        $this->expectException(InvalidFleetMission::class);

        $this->dispatch()->dispatch($fleet, [$step], 100, new Resources());
    }

    public function testSpeedPercentGoesByTens(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 1]);

        $this->expectException(InvalidFleetMission::class);

        $this->dispatch()->dispatch($fleet, [new MissionStep(SpacePosition::planet($this->colonyOf($empire)), FleetAction::Station, 'colonie')], 55, new Resources());
    }

    private function empire(): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $empire->getHomePlanet()->storeResources(new Resources(10_000, 10_000, 10_000), $this->clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $empire;
    }

    /** Colonie dans un autre système de la même galaxie */
    private function colonyOf(Empire $empire): Planet
    {
        $home = $empire->getHomePlanet()->getSystem();
        $colony = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 1500, $home->getPosition()->y),
        ])]);
        $colony->assignTo($empire);
        $colony->storeResources(new Resources(100, 100, 100), $this->clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $colony;
    }

    /** Planète libre dans un autre système de la galaxie de l'empire */
    private function freePlanetNear(Empire $empire): Planet
    {
        $home = $empire->getHomePlanet()->getSystem();

        return PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x, $home->getPosition()->y + 1200),
        ])]);
    }

    private function ship(string $code): ShipType
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        \assert($type instanceof ShipType);

        return $type;
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

    private function reloadPlanet(Planet $planet): Planet
    {
        $planet = self::getContainer()->get(EntityManagerInterface::class)->find(Planet::class, $planet->getId());
        \assert($planet instanceof Planet);

        return $planet;
    }

    private function dispatch(): FleetDispatch
    {
        return self::getContainer()->get(FleetDispatch::class);
    }
}
