<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Ranking;

use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Enum\Ranking\RankingCategory;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Repository\BuildingTypeRepository;
use App\Repository\EmpireScoreRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Alliance\Alliances;
use App\Service\Ranking\RankingRules;
use App\Service\Ranking\Rankings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Recalcul des scores (§4.11) : chaque catégorie agrège bien les investissements de l'empire, et les classements des
 * empires et des alliances se lisent dans l'ordre.
 */
final class RankingsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-13 10:00:00');
    }

    public function testScoresAggregateBuildingsResearchShipsAndColonies(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana', 'foundedAt' => $this->clock->now()]);
        $home = $ana->getHomePlanet();
        $mine = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('metal_mine');
        $laser = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('weapons');
        \assert(null !== $mine && null !== $laser);
        $home->setBuildingLevel($mine, 12);
        $ana->setResearchLevel($laser, 6);
        $home->addShips($this->type('rocket_launcher'), 40);
        $fleet = new Fleet($ana, 'Escadre', $home, $this->clock->now());
        $fleet->addShips($this->type('cruiser'), 10);
        $this->entityManager()->persist($fleet);
        $colony = PlanetFactory::createOne();
        $colony->assignTo($ana);
        $colony->setBuildingLevel($mine, 3);
        $this->entityManager()->flush();

        self::assertSame(1, $this->refreshCount($ana));

        $rules = new RankingRules();
        $buildings = 0.0;
        foreach ([$home, $colony] as $planet) {
            foreach ($planet->getBuildings() as $building) {
                $buildings += $rules->levelsCost($building->getType()->getBaseCost(), $building->getType()->getCostFactor(), $building->getLevel());
            }
        }
        $research = 0.0;
        foreach ($ana->getResearches() as $level) {
            $research += $rules->levelsCost($level->getTechnology()->getBaseCost(), $level->getTechnology()->getCostFactor(), $level->getLevel());
        }
        $ships = $rules->unitsCost($this->type('rocket_launcher')->getCost(), 40) + $rules->unitsCost($this->type('cruiser')->getCost(), 10);
        $expected = $rules->score($buildings, $research, $ships, 1, 0);

        $score = $this->scoreOf($ana);
        self::assertSame([$expected->economy, $expected->research, $expected->military, RankingRules::POINTS_PER_COLONY, $expected->total], [
            $score->getEconomy(), $score->getResearch(), $score->getMilitary(), $score->getExploration(), $score->getTotal(),
        ]);
        self::assertGreaterThan(0, $score->getMilitary());
        // Score global recopié sur l'empire (vue d'ensemble)
        self::assertSame($expected->total, (int) $this->entityManager()->createQuery('SELECT e.score FROM ' . Empire::class . ' e WHERE e.id = :id')->setParameter('id', $ana->getId())->getSingleScalarResult());
    }

    public function testEmpiresAndAlliancesAreRankedByCategory(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana', 'foundedAt' => $this->clock->now()]);
        $bob = EmpireFactory::createOne(['name' => 'Bob', 'foundedAt' => $this->clock->now()]);
        $cid = EmpireFactory::createOne(['name' => 'Cid', 'foundedAt' => $this->clock->now()]);
        // Bob : la plus grosse flotte ; Ana : le plus de recherche
        $fleet = new Fleet($bob, 'Armada', $bob->getHomePlanet(), $this->clock->now());
        $fleet->addShips($this->type('battleship'), 50);
        $this->entityManager()->persist($fleet);
        $laser = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('weapons');
        \assert(null !== $laser);
        $ana->setResearchLevel($laser, 12);
        $this->entityManager()->flush();
        $alliances = self::getContainer()->get(Alliances::class);
        $alliances->found($ana, 'Ligue', 'LGS');
        $alliances->accept($alliances->invite($ana, 'Cid'), $cid);
        $alliances->found($bob, 'Horde', 'HRD');

        self::getContainer()->get(Rankings::class)->refresh();
        $scores = self::getContainer()->get(EmpireScoreRepository::class);

        self::assertSame(['Bob'], array_slice(array_map(static fn(array $row): string => $row['score']->getEmpire()->getName(), $scores->findPage(RankingCategory::Military, 0, 50)), 0, 1));
        self::assertSame(['Ana'], array_slice(array_map(static fn(array $row): string => $row['score']->getEmpire()->getName(), $scores->findPage(RankingCategory::Research, 0, 50)), 0, 1));
        self::assertSame(1, $scores->rankOf($bob, RankingCategory::Military));
        self::assertSame(1, $scores->rankOf($ana, RankingCategory::Research));
        self::assertSame('LGS', $scores->findPage(RankingCategory::Research, 0, 1)[0]['alliance']);

        // Alliances : points cumulés des membres
        $military = $scores->findAlliances(RankingCategory::Military, 0, 50);
        self::assertSame(['Horde', 'Ligue'], array_map(static fn(array $row): string => $row['alliance']->getName(), $military));
        self::assertSame(2, $military[1]['members']);
        self::assertSame($this->scoreOf($ana)->getMilitary() + $this->scoreOf($cid)->getMilitary(), $military[1]['points']);
        self::assertSame(2, $scores->countAlliances());
    }

    private function refreshCount(Empire $empire): int
    {
        self::getContainer()->get(Rankings::class)->refresh();

        return self::getContainer()->get(EmpireScoreRepository::class)->count(['empire' => $empire]);
    }

    private function scoreOf(Empire $empire): EmpireScore
    {
        $score = self::getContainer()->get(EmpireScoreRepository::class)->findOneBy(['empire' => $empire]);
        \assert(null !== $score);

        return $score;
    }

    private function type(string $code): ShipType
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        \assert($type instanceof ShipType);

        return $type;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
