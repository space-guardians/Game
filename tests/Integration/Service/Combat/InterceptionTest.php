<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Combat;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Fleet\FleetStatus;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\ShipTypeRepository;
use App\Service\Alliance\Alliances;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Stationnement au niveau d'un système (§4.6.2) : les flottes qui y stationnent interceptent toute flotte hostile qui
 * entre dans le système, quelle que soit sa destination.
 */
final class InterceptionTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-11 10:00:00');
    }

    public function testGuardsDestroyAWeakIntruderBeforeItReachesItsPlanet(): void
    {
        [$intruder, $planet] = $this->scene(['light_fighter' => 3], ['battleship' => 30]);
        $intruderId = $intruder->getId();

        $this->arrive($intruder, $planet);

        self::assertNull($this->entityManager()->find(Fleet::class, $intruderId));
        $battle = $this->entityManager()->getRepository(Battle::class)->findOneBy([]);
        self::assertStringContainsString('(interception)', (string) $battle?->getLocationLabel());
        self::assertSame(CombatSide::Defender, $battle?->getWinner());
        self::assertNull($battle->getLocation()->planetId);
    }

    public function testIntruderHeldOffIsStoppedAtTheSystem(): void
    {
        // Transporteurs contre transporteurs : leurs tirs ricochent, personne ne tombe
        [$intruder, $planet] = $this->scene(['small_cargo' => 5], ['small_cargo' => 5]);

        $this->arrive($intruder, $planet, thenStation: true);

        $intruder = $this->reload($intruder);
        self::assertSame(FleetStatus::Stationed, $intruder->getStatus());
        self::assertSame($planet->getSystem()->getId(), $intruder->getLocation()->systemId);
        self::assertNull($intruder->getLocation()->planetId);
        self::assertSame(FleetOrderStatus::Failed, $intruder->getOrders()[0]?->getStatus());
        self::assertStringContainsString('Interceptée à l’entrée du système', (string) $intruder->getOrders()[0]->getFailure());
        // Ses ordres restants attendent
        self::assertSame(FleetOrderStatus::Pending, $intruder->getOrders()[1]?->getStatus());
    }

    public function testIntruderThatBreaksTheGuardsCarriesOn(): void
    {
        [$intruder, $planet] = $this->scene(['battleship' => 30], ['light_fighter' => 2]);

        $this->arrive($intruder, $planet);

        $intruder = $this->reload($intruder);
        self::assertSame(FleetOrderStatus::Done, $intruder->getOrders()[0]?->getStatus());
        self::assertSame($planet->getId(), $intruder->getLocation()->planetId);
        self::assertSame(CombatSide::Attacker, $this->entityManager()->getRepository(Battle::class)->findOneBy([])?->getWinner());
    }

    public function testFleetsOfTheSameEmpireAreNotIntercepted(): void
    {
        [$intruder, $planet] = $this->scene(['light_fighter' => 3], ['battleship' => 30], sameEmpire: true);

        $this->arrive($intruder, $planet);

        self::assertSame(FleetOrderStatus::Done, $this->reload($intruder)->getOrders()[0]?->getStatus());
        self::assertSame(0, $this->entityManager()->getRepository(Battle::class)->count([]));
    }

    public function testFleetsOfAnAllyAreNotIntercepted(): void
    {
        [$intruder, $planet] = $this->scene(['light_fighter' => 3], ['battleship' => 30]);
        $guard = $this->entityManager()->getRepository(Fleet::class)->findOneBy(['location.systemId' => $planet->getSystem()->getId()]);
        \assert($guard instanceof Fleet);
        $alliances = self::getContainer()->get(Alliances::class);
        $alliances->found($intruder->getEmpire(), 'Ligue', 'LGS');
        $alliances->accept($alliances->invite($intruder->getEmpire(), $guard->getEmpire()->getName()), $guard->getEmpire());

        $this->arrive($intruder, $planet);

        self::assertSame(FleetOrderStatus::Done, $this->reload($intruder)->getOrders()[0]?->getStatus());
        self::assertSame(0, $this->entityManager()->getRepository(Battle::class)->count([]));
    }

    public function testGuardsAtAPlanetDoNotIntercept(): void
    {
        [$intruder, $planet] = $this->scene(['light_fighter' => 3], ['battleship' => 30], guardAtSystemLevel: false);

        $this->arrive($intruder, $this->otherPlanetOf($planet));

        self::assertSame(FleetOrderStatus::Done, $this->reload($intruder)->getOrders()[0]?->getStatus());
        self::assertSame(0, $this->entityManager()->getRepository(Battle::class)->count([]));
    }

    /**
     * Un intrus part d'un système voisin vers une planète d'un système gardé.
     *
     * @param array<string, int> $intruderShips
     * @param array<string, int> $guardShips
     *
     * @return array{Fleet, Planet}
     */
    private function scene(array $intruderShips, array $guardShips, bool $sameEmpire = false, bool $guardAtSystemLevel = true): array
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $home = $empire->getHomePlanet()->getSystem();
        $planet = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 3000, $home->getPosition()->y),
        ])]);
        $guard = $this->fleet($sameEmpire ? $empire : EmpireFactory::createOne(), $planet, $guardShips);
        if ($guardAtSystemLevel) {
            $guard->arriveAt(SpaceLocation::of(SpacePosition::system($planet->getSystem())), null);
        }
        $intruder = $this->fleet($empire, $empire->getHomePlanet(), $intruderShips);
        $intruder->refuel($intruder->tankCapacity());
        $this->entityManager()->flush();

        return [$intruder, $planet];
    }

    private function otherPlanetOf(Planet $planet): Planet
    {
        $orbit = $planet->getPosition()->orbit + 1;

        return PlanetFactory::createOne(['system' => $planet->getSystem(), 'position' => new OrbitalPosition($orbit, $orbit * 10.0, 0.0)]);
    }

    private function arrive(Fleet $fleet, Planet $planet, bool $thenStation = false): FleetMovement
    {
        $steps = [new MissionStep(SpacePosition::planet($planet), FleetAction::Station, 'cible')];
        if ($thenStation) {
            $steps[] = new MissionStep(SpacePosition::planet($fleet->getEmpire()->getHomePlanet()), FleetAction::Station, 'retour');
        }
        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, $steps, 100, new Resources());
        $this->clock->sleep($movement->getDurationSeconds());
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId());

        return $movement;
    }

    /** @param array<string, int> $ships */
    private function fleet(Empire $empire, Planet $at, array $ships): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $at, $this->clock->now());
        foreach ($ships as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $fleet->addShips($type, $quantity);
        }
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
