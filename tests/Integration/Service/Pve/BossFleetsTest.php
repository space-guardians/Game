<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Pve;

use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Model\Ranking\ScoreBreakdown;
use App\Repository\FleetRepository;
use App\Repository\NpcBehaviorProfileRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Pve\BossFleetRules;
use App\Service\Pve\BossFleets;
use App\Service\Pve\NpcCivilisations;
use App\Service\Ranking\RankingRules;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Recalcul de la flotte des « boss » (§4.14) : indexée sur la flotte militaire moyenne du top 10 des joueurs, à
 * l'intervalle du profil.
 */
final class BossFleetsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 17:00:00');
    }

    public function testTheBossFleetFollowsTheTopTenMilitaryAverage(): void
    {
        $boss = $this->boss();
        // Le profil de départ vaut 150 % de la moyenne du top 10 ; une civilisation n'entre pas dans ce top
        $this->military(EmpireFactory::createOne(), 2000);
        $this->military(EmpireFactory::createOne(), 4000);
        $this->military($boss, 1_000_000);

        self::assertSame(1, $this->fleets()->recalculateDue());

        $expected = $this->expected(4500.0);
        self::assertSame($expected, $this->homeFleetOf($boss));
        self::assertEquals($this->clock->now(), $boss->getNpcFleetRecalculatedAt());

        // Pas avant la fin de l'intervalle (24 h) ; ensuite, la flotte suit la baisse du classement
        $this->clock->sleep(3600);
        self::assertSame(0, $this->fleets()->recalculateDue());
        foreach (self::getContainer()->get(EntityManagerInterface::class)->getRepository(EmpireScore::class)->findAll() as $score) {
            if (!$score->getEmpire()->isNpc()) {
                $score->update(new ScoreBreakdown(0, 0, 1000, 0, 1000), $this->clock->now());
            }
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->clock->sleep(86400);
        self::assertSame(1, $this->fleets()->recalculateDue());
        self::assertSame($this->expected(1500.0), $this->homeFleetOf($boss));
    }

    public function testOnlyABossHasItsFleetRecalculated(): void
    {
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('minor_raider');
        \assert(null !== $profile);
        $minor = self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Pillards', PlanetFactory::createOne());

        $this->expectException(\DomainException::class);
        $this->fleets()->recalculate($minor);
    }

    private function boss(): Empire
    {
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('boss_guardian');
        \assert(null !== $profile);

        return self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Gardiens', PlanetFactory::createOne());
    }

    private function military(Empire $empire, int $points): void
    {
        $score = new EmpireScore($empire, $this->clock->now());
        $score->update(new ScoreBreakdown(0, 0, $points, 0, $points), $this->clock->now());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($score);
        $entityManager->flush();
    }

    /** @return array<string, int> */
    private function expected(float $target): array
    {
        $points = [];
        foreach (array_keys(BossFleetRules::BASE) as $code) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert(null !== $type);
            $points[$code] = new RankingRules()->unitsCost($type->getCost(), 1) / RankingRules::RESOURCES_PER_POINT;
        }

        $composition = new BossFleetRules()->composition(BossFleetRules::BASE, $points, $target);
        ksort($composition);

        return $composition;
    }

    /** @return array<string, int> */
    private function homeFleetOf(Empire $boss): array
    {
        $fleets = self::getContainer()->get(FleetRepository::class)->findBy(['empire' => $boss]);
        self::assertCount(1, $fleets);
        $counts = $fleets[0]->shipCounts();
        ksort($counts);

        return $counts;
    }

    private function fleets(): BossFleets
    {
        return self::getContainer()->get(BossFleets::class);
    }
}
