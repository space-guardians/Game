<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Combat;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Enum\Combat\BattleStatus;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Fleet\FleetStatus;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\BattleRepository;
use App\Repository\BuildingTypeRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Combat\BattleResolutionHandler;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Batailles programmées (§4.7) : une attaque lancée devient une bataille au lieu visé, résolue à l'arrivée du premier
 * assaillant contre les flottes hostiles présentes.
 */
final class BattleTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-11 10:00:00');
    }

    public function testAttackBecomesAScheduledBattleResolvedAtArrival(): void
    {
        $attacker = $this->empire();
        $target = $this->planetNear($attacker);
        $defenceId = $this->fleet(EmpireFactory::createOne(), $target, ['light_fighter' => 5])->getId();
        $raid = $this->fleet($attacker, $attacker->getHomePlanet(), ['cruiser' => 20]);

        $movement = $this->attack($raid, $target, thenReturn: true);

        $battle = $this->battles()->findScheduledFor($raid);
        self::assertInstanceOf(Battle::class, $battle);
        self::assertEquals($movement->getArrivesAt(), $battle->getResolvesAt());
        self::assertSame((string) $target->getAddress(), $battle->getLocationLabel());
        self::assertSame(CombatSide::Attacker, $battle->participantFor($raid)?->getSide());

        // Planète visée : arrivée puis bataille, dues au même instant, se résolvent ensemble sous son verrou
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId(), 2);

        $battle = $this->reloadBattle($battle);
        self::assertSame(BattleStatus::Resolved, $battle->getStatus());
        self::assertSame(CombatSide::Attacker, $battle->getWinner());
        self::assertGreaterThan(0, $battle->getRounds());
        self::assertSame(['light_fighter' => 5], $battle->getLosses()[CombatSide::Defender->value]);
        // Défenseur présent enregistré, anéanti : sa flotte a disparu
        $defenderParticipant = $battle->getParticipants()->findFirst(static fn(int $key, $participant): bool => CombatSide::Defender === $participant->getSide());
        self::assertSame('Escadre', $defenderParticipant?->getFleetName());
        self::assertNull($defenderParticipant->getFleet());
        self::assertSame(['light_fighter' => 5], $defenderParticipant->getLosses());
        self::assertNull($this->entityManager()->find(Fleet::class, $defenceId));
        // L'assaillant termine son attaque et rentre
        $raid = $this->reload($raid);
        self::assertSame(FleetOrderStatus::Done, $raid->getOrders()[0]?->getStatus());
        self::assertSame(FleetStatus::InFlight, $raid->getStatus());
    }

    public function testResolutionWaitsForAnAttackerWhoseArrivalIsNotProcessedYet(): void
    {
        $attacker = $this->empire();
        $raid = $this->fleet($attacker, $attacker->getHomePlanet(), ['cruiser' => 5]);
        // Système visé : arrivée et bataille sont des événements indépendants
        $movement = $this->attackAt($raid, SpacePosition::system($this->planetNear($attacker)->getSystem()));
        $this->clock->sleep($movement->getDurationSeconds());
        $first = $this->pendingResolution();

        // La résolution passe avant l'arrivée : elle se reprogramme quelques secondes plus tard
        $this->resolve((int) $first->getId());
        self::assertSame(BattleStatus::Scheduled, $this->battles()->findScheduledFor($this->reload($raid))?->getStatus());
        $retry = $this->pendingResolution();
        self::assertGreaterThan($first->getDueAt(), $retry->getDueAt());

        // Arrivée : la flotte attend la bataille sur place, ordre en cours ; puis la bataille se résout
        $this->resolve((int) $movement->getEvent()?->getId());
        $raid = $this->reload($raid);
        self::assertSame(FleetStatus::Stationed, $raid->getStatus());
        self::assertSame(FleetOrderStatus::InProgress, $raid->getOrders()[0]?->getStatus());
        $this->clock->sleep(5);
        $this->resolve((int) $retry->getId());
        self::assertSame(BattleStatus::Resolved, $this->entityManager()->getRepository(Battle::class)->findOneBy([])?->getStatus());
    }

    public function testAttackArrivingInTimeJoinsTheScheduledBattle(): void
    {
        $attacker = $this->empire();
        $target = $this->planetNear($attacker);
        $first = $this->fleet($attacker, $attacker->getHomePlanet(), ['cruiser' => 5]);
        $second = $this->fleet($attacker, $attacker->getHomePlanet(), ['cruiser' => 5]);

        $this->attack($first, $target);
        $this->attack($second, $target);

        $battle = $this->battles()->findScheduledFor($first);
        self::assertSame($battle, $this->battles()->findScheduledFor($second));
        self::assertCount(2, $battle?->getParticipants() ?? []);
        self::assertSame(1, $this->entityManager()->getRepository(ScheduledEvent::class)->count(['type' => BattleResolutionHandler::TYPE]));
    }

    public function testStrongerDefenderWinsAndTheRaidIsDestroyed(): void
    {
        $attacker = $this->empire();
        $target = $this->planetNear($attacker);
        $guard = $this->fleet(EmpireFactory::createOne(), $target, ['battleship' => 30]);
        $raid = $this->fleet($attacker, $attacker->getHomePlanet(), ['light_fighter' => 3]);
        $raidId = $raid->getId();

        $this->fight($raid, $target);

        $battle = $this->entityManager()->getRepository(Battle::class)->findOneBy([]);
        self::assertSame(CombatSide::Defender, $battle?->getWinner());
        self::assertNull($this->entityManager()->find(Fleet::class, $raidId));
        self::assertSame(30, $this->reload($guard)->shipCount());
    }

    public function testUndefendedTargetFallsWithoutCombat(): void
    {
        $attacker = $this->empire();
        $raid = $this->fleet($attacker, $attacker->getHomePlanet(), ['cruiser' => 2]);

        $this->fight($raid, $this->planetNear($attacker));

        $battle = $this->entityManager()->getRepository(Battle::class)->findOneBy([]);
        self::assertSame(CombatSide::Attacker, $battle?->getWinner());
        self::assertSame(0, $battle->getRounds());
    }

    public function testBattleIsCancelledWhenNoAttackerIsLeftOnSite(): void
    {
        $attacker = $this->empire();
        $target = $this->planetNear($attacker);
        $raid = $this->fleet($attacker, $attacker->getHomePlanet(), ['cruiser' => 2]);
        $movement = $this->attackAt($raid, SpacePosition::system($target->getSystem()));
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId());
        $raid = $this->reload($raid);
        $resolution = $this->pendingResolution();
        // L'assaillant repart avant l'heure de la bataille
        $this->dispatch()->dispatch($raid, [new MissionStep(SpacePosition::planet($raid->getEmpire()->getHomePlanet()), FleetAction::Station, 'retour')], 100, new Resources());

        $this->resolve((int) $resolution->getId());

        self::assertSame(BattleStatus::Cancelled, $this->entityManager()->getRepository(Battle::class)->findOneBy([])?->getStatus());
    }

    private function fight(Fleet $raid, Planet $target): void
    {
        $movement = $this->attack($raid, $target);
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve((int) $movement->getEvent()?->getId(), 2);
    }

    private function attack(Fleet $fleet, Planet $target, bool $thenReturn = false): FleetMovement
    {
        return $this->attackAt($fleet, SpacePosition::planet($target), $thenReturn, (string) $target->getAddress());
    }

    private function attackAt(Fleet $fleet, SpacePosition $target, bool $thenReturn = false, string $label = 'cible'): FleetMovement
    {
        $steps = [new MissionStep($target, FleetAction::JoinBattle, $label)];
        if ($thenReturn) {
            $steps[] = new MissionStep(SpacePosition::planet($fleet->getEmpire()->getHomePlanet()), FleetAction::Station, 'retour');
        }

        return $this->dispatch()->dispatch($fleet, $steps, 100, new Resources(), $fleet->tankCapacity() - $fleet->getFuel());
    }

    private function pendingResolution(): ScheduledEvent
    {
        $event = $this->entityManager()->getRepository(ScheduledEvent::class)->findOneBy(['type' => BattleResolutionHandler::TYPE, 'status' => ScheduledEventStatus::Pending], ['dueAt' => 'DESC']);
        \assert($event instanceof ScheduledEvent);

        return $event;
    }

    private function resolve(int $eventId, int $expected = 1): void
    {
        self::assertSame($expected, self::getContainer()->get(ScheduledEventResolver::class)->resolve($eventId));
    }

    private function empire(): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $planet = $empire->getHomePlanet();
        foreach (['metal_storage', 'crystal_storage', 'deuterium_storage'] as $storage) {
            $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($storage);
            \assert(null !== $type);
            $planet->setBuildingLevel($type, 12);
        }
        $planet->storeResources(new Resources(0, 0, 500_000), $this->clock->now());
        $this->entityManager()->flush();

        return $empire;
    }

    /** Planète d'un système voisin, à 3 000 du système de l'empire */
    private function planetNear(Empire $empire): Planet
    {
        $home = $empire->getHomePlanet()->getSystem();

        return PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 3000, $home->getPosition()->y),
        ])]);
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

    private function reloadBattle(Battle $battle): Battle
    {
        $this->entityManager()->clear();
        $battle = $this->entityManager()->find(Battle::class, $battle->getId());
        \assert($battle instanceof Battle);

        return $battle;
    }

    private function battles(): BattleRepository
    {
        return self::getContainer()->get(BattleRepository::class);
    }

    private function dispatch(): FleetDispatch
    {
        return self::getContainer()->get(FleetDispatch::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
