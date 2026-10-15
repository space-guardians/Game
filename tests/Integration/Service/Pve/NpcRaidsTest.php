<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Pve;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Enum\Combat\BattleStatus;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Repository\NpcBehaviorProfileRepository;
use App\Service\Combat\IncomingAttacks;
use App\Service\Pve\NpcCivilisations;
use App\Service\Pve\NpcRaids;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Raids des civilisations mineures (§4.14) : sur la planète de joueur la plus proche dans leur portée, avec le moteur
 * de flotte et de combat des joueurs, au plus une fois par intervalle ; jamais pour un « boss ».
 */
final class NpcRaidsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 10:00:00');
    }

    public function testAMinorCivilisationRaidsTheNearestPlayerOncePerInterval(): void
    {
        $player = EmpireFactory::createOne(['name' => 'Ana']);
        $npc = $this->civilisation('minor_raider', $player->getHomePlanet(), 800.0);

        self::assertSame(1, $this->raids()->launchDue());

        // Une bataille programmée sur la planète du joueur, qui la voit venir
        $attacks = self::getContainer()->get(IncomingAttacks::class)->of($player);
        self::assertCount(1, $attacks);
        self::assertSame($npc, $attacks[0]->attacker);
        self::assertSame(BattleStatus::Scheduled, self::getContainer()->get(EntityManagerInterface::class)->find(Battle::class, $attacks[0]->battleId)?->getStatus());
        self::assertEquals($this->clock->now(), $npc->getNpcLastRaidAt());

        // Pas de nouveau raid avant la fin de l'intervalle
        $this->clock->sleep(3600);
        self::assertSame(0, $this->raids()->launchDue());
    }

    public function testNoRaidOutOfRangeNorFromABoss(): void
    {
        $player = EmpireFactory::createOne(['name' => 'Ana']);
        $this->civilisation('minor_raider', $player->getHomePlanet(), 50_000.0);
        $this->civilisation('boss_guardian', $player->getHomePlanet(), 500.0);

        self::assertSame(0, $this->raids()->launchDue());
        self::assertSame([], self::getContainer()->get(IncomingAttacks::class)->of($player));
    }

    /** Civilisation fondée sur une planète libre de la galaxie du joueur, à cette distance de sa planète mère */
    private function civilisation(string $profile, Planet $near, float $distance): Empire
    {
        $found = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode($profile);
        \assert(null !== $found);
        $system = $near->getSystem();
        $planet = PlanetFactory::createOne(['system' => StarSystemFactory::new(['galaxy' => $system->getGalaxy(), 'position' => new GlobalPosition($system->getPosition()->x + $distance, $system->getPosition()->y)])]);

        return self::getContainer()->get(NpcCivilisations::class)->found($found, \sprintf('Civilisation %d', (int) $distance), $planet);
    }

    private function raids(): NpcRaids
    {
        return self::getContainer()->get(NpcRaids::class);
    }
}
