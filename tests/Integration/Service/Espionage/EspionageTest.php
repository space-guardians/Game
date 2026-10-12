<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Espionage;

use App\Entity\CombatReport;
use App\Entity\Empire;
use App\Entity\EspionageReport;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\BuildingTypeRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Espionnage (§4.9) : sondes envoyées sur une planète ou auprès d'une flotte, rapport selon le niveau de détail.
 */
final class EspionageTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-11 10:00:00');
    }

    public function testEqualTechnologiesRevealResourcesAndFleets(): void
    {
        [$spy, $planet] = $this->target();

        $this->send($this->probes($spy, 1), SpacePosition::planet($planet));

        $report = $this->report();
        self::assertSame('Cible', $report->getTargetName());
        self::assertSame(0, $report->getScore());
        self::assertTrue($report->reveals('resources'));
        self::assertGreaterThanOrEqual(5_000, $report->getSections()['resources']['metal']);
        self::assertSame([['name' => 'Garde', 'empire' => 'Cible', 'ships' => ['Croiseur' => 4]]], $report->getSections()['fleets']);
        self::assertFalse($report->reveals('defenses'));
        self::assertFalse($report->reveals('research'));
    }

    public function testBetterEspionageRevealsEverything(): void
    {
        [$spy, $planet] = $this->target();
        $this->research($spy, 'espionage', 3);

        $this->send($this->probes($spy, 1), SpacePosition::planet($planet));

        $sections = $this->report()->getSections();
        self::assertSame(['Lanceur de missiles' => 10], $sections['defenses']);
        self::assertSame(['Mine de métal' => 4], $sections['buildings']);
        self::assertSame(['Armement' => 2], $sections['research']);
    }

    public function testFleetsAtAPositionCanBeSpiedOn(): void
    {
        [$spy, $planet] = $this->target();
        // Un point précis du système (au niveau du système, la flotte intercepterait les sondes, #47)
        $point = SpacePosition::inSystem($planet->getSystem(), 50.0, 0.0);
        $this->fleet(EmpireFactory::createOne(['name' => 'Rodeur']), $planet, 'Patrouille', ['light_fighter' => 3])->arriveAt(SpaceLocation::of($point), null);
        $this->entityManager()->flush();

        $this->send($this->probes($spy, 2), $point);

        $report = $this->report();
        self::assertSame('Rodeur', $report->getTargetName());
        self::assertFalse($report->reveals('resources'));
        self::assertSame('Patrouille', $report->getSections()['fleets'][0]['name']);
    }

    public function testNothingToSpyOnFailsTheOrder(): void
    {
        [$spy, $planet] = $this->target();
        $probes = $this->probes($spy, 1);

        $this->send($probes, SpacePosition::system($planet->getSystem()));

        $this->entityManager()->clear();
        $probes = $this->entityManager()->find(Fleet::class, $probes->getId());
        self::assertSame(FleetOrderStatus::Failed, $probes?->getOrders()[0]?->getStatus());
        self::assertSame('Aucune flotte à espionner à cette position.', $probes->getOrders()[0]->getFailure());
    }

    public function testProbesRiskDetectionWithEqualTechnologies(): void
    {
        [$spy, $planet] = $this->target();

        $this->send($this->probes($spy, 3), SpacePosition::planet($planet));

        // 3 sondes, niveaux égaux : 6 %
        self::assertEqualsWithDelta(0.06, $this->report()->getDetectionChance(), 1e-9);
    }

    public function testDetectedProbesAreShotDownAndTheTargetIsWarned(): void
    {
        [$spy, $planet] = $this->target();
        // Cible bien plus avancée : détection certaine
        $owner = $planet->getOwner();
        \assert(null !== $owner);
        $this->research($owner, 'espionage', 8);
        $probes = $this->probes($spy, 1);
        $probesId = $probes->getId();

        $this->send($probes, SpacePosition::planet($planet));

        $report = $this->report();
        self::assertSame(1.0, $report->getDetectionChance());
        self::assertTrue($report->isDetected());
        // La garde et les lanceurs de missiles abattent la sonde
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Fleet::class, $probesId));
        $combat = $this->entityManager()->getRepository(CombatReport::class)->findOneBy([]);
        self::assertStringContainsString('(sondes repérées)', (string) $combat?->getLocationLabel());
        self::assertSame(CombatSide::Defender, $combat?->getWinner());
        // Alerte en direct à la cible
        $topics = array_map(static fn($update): string => $update->getTopics()[0] . ' ' . $update->getData(), self::getContainer()->get(PublishedUpdates::class)->all());
        self::assertNotEmpty(array_filter($topics, static fn(string $line): bool => str_starts_with($line, '/empire/' . $owner->getId()) && str_contains($line, 'Sondes d’espionnage de')));
    }

    public function testDetectedProbesWithNobodyToFightThemCarryOn(): void
    {
        [$spy, $planet] = $this->target(guarded: false);
        $owner = $planet->getOwner();
        \assert(null !== $owner);
        $this->research($owner, 'espionage', 8);
        $probes = $this->probes($spy, 1);

        $this->send($probes, SpacePosition::planet($planet));

        self::assertTrue($this->report()->isDetected());
        $this->entityManager()->clear();
        $probes = $this->entityManager()->find(Fleet::class, $probes->getId());
        self::assertSame(FleetOrderStatus::Done, $probes?->getOrders()[0]?->getStatus());
        self::assertSame(0, $this->entityManager()->getRepository(CombatReport::class)->count([]));
    }

    public function testOnlyProbesCanSpy(): void
    {
        [$spy, $planet] = $this->target();
        $fleet = $this->probes($spy, 1);
        $fleet->addShips($this->type('light_fighter'), 1);

        $this->expectExceptionObject(new InvalidFleetMission('Ordre 1 : « Espionnage » demande une flotte composée uniquement de sondes d’espionnage.'));

        $this->send($fleet, SpacePosition::planet($planet));
    }

    /**
     * Planète d'un empire « Cible », voisine de l'espion : stock, mine, défenses, flotte, recherche.
     *
     * @return array{Empire, Planet}
     */
    private function target(bool $guarded = true): array
    {
        $spy = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $home = $spy->getHomePlanet()->getSystem();
        $planet = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 3000, $home->getPosition()->y),
        ])]);
        $owner = EmpireFactory::createOne(['name' => 'Cible']);
        $planet->assignTo($owner);
        $planet->storeResources(new Resources(5_000, 3_000, 1_000), $this->clock->now());
        $mine = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('metal_mine');
        \assert(null !== $mine);
        $planet->setBuildingLevel($mine, 4);
        $this->research($owner, 'weapons', 2);
        if ($guarded) {
            $planet->addShips($this->type('rocket_launcher'), 10);
            $this->fleet($owner, $planet, 'Garde', ['cruiser' => 4]);
        }

        return [$spy, $planet];
    }

    private function send(Fleet $fleet, SpacePosition $target): void
    {
        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep($target, FleetAction::Spy, 'cible')], 100, new Resources());
        $this->clock->sleep($movement->getDurationSeconds());
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId());
    }

    private function report(): EspionageReport
    {
        $report = $this->entityManager()->getRepository(EspionageReport::class)->findOneBy([]);
        \assert($report instanceof EspionageReport);

        return $report;
    }

    private function probes(Empire $empire, int $count): Fleet
    {
        $fleet = $this->fleet($empire, $empire->getHomePlanet(), 'Sondes', ['espionage_probe' => $count]);
        $fleet->refuel($fleet->tankCapacity());
        $this->entityManager()->flush();

        return $fleet;
    }

    /** @param array<string, int> $ships */
    private function fleet(Empire $empire, Planet $at, string $name, array $ships): Fleet
    {
        $fleet = new Fleet($empire, $name, $at, $this->clock->now());
        foreach ($ships as $code => $quantity) {
            $fleet->addShips($this->type($code), $quantity);
        }
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();

        return $fleet;
    }

    private function research(Empire $empire, string $code, int $level): void
    {
        $technology = self::getContainer()->get(TechnologyRepository::class)->findOneByCode($code);
        \assert(null !== $technology);
        $empire->setResearchLevel($technology, $level);
        $this->entityManager()->flush();
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
