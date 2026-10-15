<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Pve;

use App\Entity\Battle;
use App\Entity\BattleParticipant;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Enum\Combat\BattleStatus;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetRepository;
use App\Repository\NpcBehaviorProfileRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Combat\BattleResolutionHandler;
use App\Service\Fleet\FleetDispatch;
use App\Service\Pve\NpcCivilisations;
use App\Service\Pve\NpcRaids;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Civilisations « boss » strictement défensives (§4.14) : elles ne lancent jamais d'attaque, mais leur flotte et leurs
 * défenses ripostent quand on attaque leur planète.
 */
final class BossDefenseTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 17:00:00');
    }

    public function testABossNeverLaunchesAnAttack(): void
    {
        [$player, $boss] = $this->neighbours();
        $fleet = self::getContainer()->get(FleetRepository::class)->findOneBy(['empire' => $boss]);
        \assert($fleet instanceof Fleet);

        self::assertSame('Une civilisation « boss » est strictement défensive.', self::getContainer()->get(NpcRaids::class)->raidNow($boss, $player->getHomePlanet()));
        $this->expectExceptionObject(new InvalidFleetMission('Une civilisation « boss » est strictement défensive : ses flottes ne quittent pas sa planète.'));
        self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep(SpacePosition::planet($player->getHomePlanet()), FleetAction::JoinBattle, 'Ana')], 100, new Resources());
    }

    public function testItsFleetAndDefensesRetaliateWhenItsPlanetIsAttacked(): void
    {
        [$player, $boss] = $this->neighbours();
        $raid = new Fleet($player, 'Téméraires', $player->getHomePlanet(), $this->clock->now());
        $cruiser = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('cruiser');
        \assert($cruiser instanceof ShipType);
        $raid->addShips($cruiser, 2);
        $raid->refuel($raid->tankCapacity());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($raid);
        $entityManager->flush();

        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($raid, [new MissionStep(SpacePosition::planet($boss->getHomePlanet()), FleetAction::JoinBattle, 'Gardiens')], 100, new Resources());
        $this->clock->sleep($movement->getDurationSeconds());
        // L'arrivée et la bataille, dues au même instant, sont résolues ensemble
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId());
        $resolution = $entityManager->getRepository(ScheduledEvent::class)->findOneBy(['type' => BattleResolutionHandler::TYPE, 'status' => ScheduledEventStatus::Pending]);
        if ($resolution instanceof ScheduledEvent) {
            self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $resolution->getId());
        }

        $battle = $entityManager->getRepository(Battle::class)->findOneBy([]);
        \assert($battle instanceof Battle);
        self::assertSame(BattleStatus::Resolved, $battle->getStatus());
        $defenders = array_values(array_filter($battle->getParticipants()->toArray(), static fn(BattleParticipant $participant): bool => CombatSide::Defender === $participant->getSide()));
        self::assertCount(1, $defenders);
        self::assertSame('Gardiens', $defenders[0]->getEmpire()->getName());
        self::assertSame(CombatSide::Defender, $battle->getWinner());
    }

    /** @return array{Empire, Empire} un joueur et un « boss » voisins */
    private function neighbours(): array
    {
        $player = EmpireFactory::createOne(['name' => 'Ana']);
        $system = $player->getHomePlanet()->getSystem();
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('boss_guardian');
        \assert(null !== $profile);
        $planet = PlanetFactory::createOne(['system' => StarSystemFactory::new(['galaxy' => $system->getGalaxy(), 'position' => new GlobalPosition($system->getPosition()->x + 600, $system->getPosition()->y)])]);

        return [$player, self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Gardiens', $planet)];
    }
}
