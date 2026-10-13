<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Ranking;

use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Entity\EmpireSnapshot;
use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Enum\Ranking\ProgressMetric;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Model\Ranking\ScoreBreakdown;
use App\Repository\BuildingTypeRepository;
use App\Repository\EmpireSnapshotRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Economy\PlanetEconomy;
use App\Service\Ranking\EmpireProgress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Suivi de la progression des empires (§5.6.1) : instantanés périodiques (score par catégorie, production, flotte,
 * planètes) et comparaison à la moyenne et au top 10 au même instant.
 */
final class EmpireProgressTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-13 10:00:00');
    }

    public function testSnapshotRecordsScoresProductionShipsAndPlanets(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $home = $ana->getHomePlanet();
        $mine = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('metal_mine');
        \assert(null !== $mine);
        $home->setBuildingLevel($mine, 8);
        $colony = PlanetFactory::createOne();
        $colony->assignTo($ana);
        $colony->setBuildingLevel($mine, 3);
        // Défenses exclues de la flotte
        $home->addShips($this->type('rocket_launcher'), 40);
        $home->addShips($this->type(ShipType::ESPIONAGE_PROBE), 5);
        $fleet = new Fleet($ana, 'Escadre', $home, $this->clock->now());
        $fleet->addShips($this->type('cruiser'), 10);
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();
        $economy = self::getContainer()->get(PlanetEconomy::class);
        $production = 0.0;
        foreach ([$home, $colony] as $planet) {
            $rates = $economy->output($planet)->hourlyProduction;
            $production += $rates->metal + $rates->crystal + $rates->deuterium;
        }

        self::assertGreaterThanOrEqual(1, $this->progress()->snapshot());

        $snapshots = $this->repository()->findSince($ana, $this->clock->now()->modify('-1 hour'));
        self::assertCount(1, $snapshots);
        $snapshot = $snapshots[0];
        $score = $this->entityManager()->getRepository(EmpireScore::class)->findOneBy(['empire' => $ana]);
        \assert(null !== $score);
        self::assertEquals($this->clock->now(), $snapshot->getTakenAt());
        self::assertGreaterThan(0, $snapshot->getTotal());
        self::assertSame([$score->getEconomy(), $score->getResearch(), $score->getMilitary(), $score->getExploration(), $score->getTotal()], [
            $snapshot->getEconomy(), $snapshot->getResearch(), $snapshot->getMilitary(), $snapshot->getExploration(), $snapshot->getTotal(),
        ]);
        self::assertSame((int) round($production), $snapshot->getProduction());
        self::assertGreaterThan(0, $snapshot->getProduction());
        self::assertSame(15, $snapshot->getShips());
        self::assertSame(2, $snapshot->getPlanets());

        // Prise suivante : une ligne de plus, au nouvel instant
        $this->clock->sleep(6 * 3600);
        $this->progress()->snapshot();
        self::assertCount(2, $this->repository()->findSince($ana, $this->clock->now()->modify('-1 day')));
    }

    public function testBenchmarksAverageAllEmpiresAndTheTopTen(): void
    {
        $at = $this->clock->now();
        // Douze empires de 10 à 120 points : moyenne 65, top 10 = 30 à 120, moyenne 75
        foreach (range(1, 12) as $i) {
            $this->persistSnapshot(EmpireFactory::createOne(), $at, 10 * $i, ships: $i);
        }
        $this->persistSnapshot(EmpireFactory::createOne(), $at->modify('-1 day'), 500, ships: 0);
        $this->entityManager()->flush();

        $benchmarks = $this->repository()->benchmarksSince($at->modify('-2 days'));

        self::assertCount(2, $benchmarks);
        $now = $benchmarks[$at->format('Y-m-d H:i:s')];
        self::assertEqualsWithDelta(65.0, $now[ProgressMetric::Total->value]['average'], 0.001);
        self::assertEqualsWithDelta(75.0, $now[ProgressMetric::Total->value]['top'], 0.001);
        // Le top 10 se choisit sur le score global, puis on fait la moyenne de chaque grandeur
        self::assertEqualsWithDelta(7.5, $now[ProgressMetric::Ships->value]['top'], 0.001);
        self::assertEqualsWithDelta(500.0, $benchmarks[$at->modify('-1 day')->format('Y-m-d H:i:s')][ProgressMetric::Total->value]['top'], 0.001);
    }

    public function testOldSnapshotsArePurged(): void
    {
        $ana = EmpireFactory::createOne();
        $this->persistSnapshot($ana, $this->clock->now()->modify(\sprintf('-%d days', EmpireProgress::RETENTION_DAYS + 1)), 5);
        $this->persistSnapshot($ana, $this->clock->now()->modify('-30 days'), 8);
        $this->entityManager()->flush();

        $this->progress()->snapshot();

        $kept = $this->repository()->findSince($ana, $this->clock->now()->modify('-2 years'));
        // L'instantané de plus d'un an disparaît ; celui du mois dernier et celui du jour restent
        self::assertCount(2, $kept);
        self::assertEquals($this->clock->now()->modify('-30 days'), $kept[0]->getTakenAt());
        self::assertEquals($this->clock->now(), $kept[1]->getTakenAt());
    }

    private function persistSnapshot(Empire $empire, \DateTimeImmutable $at, int $total, int $ships = 0): void
    {
        $this->entityManager()->persist(new EmpireSnapshot($empire, $at, new ScoreBreakdown($total, 0, 0, 0, $total), 0, $ships, 1));
    }

    private function type(string $code): ShipType
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }

    private function progress(): EmpireProgress
    {
        return self::getContainer()->get(EmpireProgress::class);
    }

    private function repository(): EmpireSnapshotRepository
    {
        return self::getContainer()->get(EmpireSnapshotRepository::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
