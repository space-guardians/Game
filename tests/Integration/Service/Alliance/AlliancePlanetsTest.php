<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Alliance;

use App\Entity\AlliancePlanet;
use App\Entity\CombatReport;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Enum\Alliance\AllianceBuildingType;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\AlliancePlanetRepository;
use App\Repository\BuildingTypeRepository;
use App\Repository\PlanetRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Alliance\AlliancePlanets;
use App\Service\Alliance\Alliances;
use App\Service\Combat\Allegiances;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Planètes d'alliance (§4.10) : construction par le fondateur, propriété collective alimentée par les membres,
 * bâtiments uniques et défenses, attaquable, pillable et destructible.
 */
final class AlliancePlanetsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-12 10:00:00');
    }

    public function testFounderBuildsACollectivePlanetPaidFromTheActivePlanet(): void
    {
        [$founder, $mate] = $this->alliance();
        $orbit = $this->freeOrbit($founder);

        $alliancePlanet = $this->build($founder, $orbit);

        $planet = $alliancePlanet->getPlanet();
        self::assertNull($planet->getOwner());
        self::assertSame('Ligue', $planet->getAlliance()?->getName());
        self::assertSame($founder->getHomePlanet()->getSystem(), $planet->getSystem());
        self::assertSame($orbit, $planet->getPosition()->orbit);
        self::assertEquals($this->clock->now()->modify('+24 hours'), $alliancePlanet->getCompletesAt());
        self::assertSame(AlliancePlanet::FULL_INTEGRITY, $alliancePlanet->getIntegrity());
        // Coût prélevé sur la planète active du fondateur
        self::assertEqualsWithDelta(200_000 - 50_000, $founder->getHomePlanet()->getResources()->metal, 1.0);
        // Propriété collective : alliée de chaque membre, de personne d'autre
        $allegiances = self::getContainer()->get(Allegiances::class);
        self::assertTrue($allegiances->planetAllied($planet, $mate));
        self::assertFalse($allegiances->planetAllied($planet, EmpireFactory::createOne()));
    }

    public function testBuildRules(): void
    {
        [$founder, $mate] = $this->alliance();
        $orbit = $this->freeOrbit($founder);
        $home = $founder->getHomePlanet();

        foreach ([
            [fn() => $this->build($mate, $orbit), 'Seul le fondateur fait construire une planète d’alliance.'],
            [fn() => $this->build($founder, $home->getPosition()->orbit), 'Cette orbite est déjà occupée.'],
            [fn() => $this->planets()->build($founder, $home->getSystem()->getGalaxy()->getNumber(), 99_999, 1, 'Bastion'), 'Aucun système à ces coordonnées.'],
            [fn() => $this->build($founder, $orbit, ''), 'Le nom compte de 1 à 40 caractères.'],
        ] as [$attempt, $error]) {
            $this->assertRefused($attempt, $error);
        }

        $alliancePlanet = $this->build($founder, $orbit);
        // Au plus trois planètes par alliance
        $this->build($founder, $this->freeOrbit($founder), 'Avant-poste');
        $this->build($founder, $this->freeOrbit($founder), 'Relais');
        $this->assertRefused(fn() => $this->build($founder, $this->freeOrbit($founder)), 'Une alliance compte au plus 3 planète(s) d’alliance.');
        // En construction : ni bâtiment ni défense
        $this->assertRefused(fn() => $this->planets()->raise($founder, $alliancePlanet, AllianceBuildingType::Scanner), \sprintf('La planète d’alliance est en construction jusqu’au %s.', $alliancePlanet->getCompletesAt()->format('d/m H:i')));
    }

    public function testMembersFeedThePlanetThatPaysForItsBuildingsAndDefenses(): void
    {
        [$founder, $mate] = $this->alliance();
        $alliancePlanet = $this->build($founder, $this->freeOrbit($founder));
        $this->clock->sleep(AlliancePlanets::BUILD_HOURS * 3600);
        $planet = $alliancePlanet->getPlanet();

        // Un membre livre sa cargaison sur la planète d'alliance
        // Flottes au départ du système de la planète (même galaxie)
        $convoy = $this->fleet($mate, $founder->getHomePlanet(), ['large_cargo' => 10]);
        $convoy->load(new Resources(40_000, 40_000, 10_000));
        $movement = $this->dispatch()->dispatch($convoy, [new MissionStep(SpacePosition::planet($planet), FleetAction::Transport, 'Bastion')], 100, new Resources(), 0);
        $this->clock->sleep($movement->getDurationSeconds());
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId());
        self::assertEqualsWithDelta(40_000, $planet->getResources()->metal, 1.0);

        // Le simple membre ne gère pas la planète ; le fondateur y monte le scanner
        $this->assertRefused(fn() => $this->planets()->raise($mate, $alliancePlanet, AllianceBuildingType::Scanner), 'Seuls le fondateur et les diplomates gèrent la planète d’alliance.');
        self::assertSame(1, $this->planets()->raise($founder, $alliancePlanet, AllianceBuildingType::Scanner));
        self::assertEqualsWithDelta(20_000, $planet->getResources()->metal, 1.0);
        $this->assertRefused(fn() => $this->planets()->raise($founder, $alliancePlanet, AllianceBuildingType::TeleportGate), 'Stock de la planète d’alliance insuffisant : les membres l’alimentent par transport.');

        // Défenses au chantier orbital, payées sur le stock ; jamais de vaisseaux
        $order = $this->planets()->orderDefenses($founder, $alliancePlanet, $this->type('rocket_launcher'), 5);
        self::assertSame(5, $order->getQuantity());
        self::assertSame($planet, $order->getPlanet());
        $this->assertRefused(fn() => $this->planets()->orderDefenses($founder, $alliancePlanet, $this->type('light_fighter'), 1), 'Une planète d’alliance ne construit que des défenses.');
    }

    public function testMembersCannotAttackOrColonizeTheirAlliancePlanet(): void
    {
        [$founder, $mate] = $this->alliance();
        $planet = $this->build($founder, $this->freeOrbit($founder))->getPlanet();
        $raid = $this->fleet($mate, $mate->getHomePlanet(), ['cruiser' => 2]);

        $this->expectExceptionObject(new InvalidFleetMission('Ordre 1 : Ligue est un allié (même empire, alliance ou pacte) : pas d’attaque possible.'));

        $this->dispatch()->dispatch($raid, [new MissionStep(SpacePosition::planet($planet), FleetAction::JoinBattle, 'Bastion')], 100, new Resources(), $raid->tankCapacity());
    }

    public function testEnemyVictoriesPlunderAndDamageThePlanetUntilItIsDestroyed(): void
    {
        [$founder] = $this->alliance();
        $alliancePlanet = $this->build($founder, $this->freeOrbit($founder));
        $planet = $alliancePlanet->getPlanet();
        $planet->storeResources(new Resources(10_000, 10_000, 0), $this->clock->now());
        $planet->addShips($this->type('rocket_launcher'), 2);
        $this->entityManager()->flush();
        $enemy = $this->empire();

        $this->attack($this->fleet($enemy, $founder->getHomePlanet(), ['cruiser' => 20]), $planet);

        // Les défenses de la planète combattent, au nom de l'alliance ; vaincues, la planète est pillée et entamée
        $report = $this->entityManager()->getRepository(CombatReport::class)->findOneBy([]);
        self::assertSame(CombatSide::Attacker, $report?->getWinner());
        [$defenses] = $report->groupsOf(CombatSide::Defender);
        self::assertSame(['Défenses planétaires', 'Ligue'], [$defenses['fleet'], $defenses['empire']]);
        self::assertEqualsWithDelta(5_000, $report->getPlunder()['metal'] ?? 0, 1.0);
        self::assertSame(AlliancePlanet::FULL_INTEGRITY - AlliancePlanets::DAMAGE_PER_DEFEAT, $alliancePlanet->getIntegrity());

        // Intégrité épuisée : la planète est détruite et redevient libre
        $alliancePlanet->damage(50);
        $this->entityManager()->flush();
        $this->attack($this->fleet($enemy, $founder->getHomePlanet(), ['cruiser' => 5]), $planet);

        $this->entityManager()->clear();
        $planet = $this->entityManager()->find(Planet::class, $planet->getId());
        \assert($planet instanceof Planet);
        self::assertFalse($planet->isClaimed());
        self::assertSame([], self::getContainer()->get(AlliancePlanetRepository::class)->findAll());
    }

    /**
     * Fondateur et simple membre de l'alliance « Ligue ».
     *
     * @return array{Empire, Empire}
     */
    private function alliance(): array
    {
        $founder = $this->empire('Fondateur');
        $mate = $this->empire('Camarade');
        $alliances = self::getContainer()->get(Alliances::class);
        $alliances->found($founder, 'Ligue', 'LGS');
        $alliances->accept($alliances->invite($founder, 'Camarade'), $mate);

        return [$founder, $mate];
    }

    private function empire(?string $name = null): Empire
    {
        $empire = EmpireFactory::createOne(array_filter(['name' => $name, 'foundedAt' => $this->clock->now()]));
        $planet = $empire->getHomePlanet();
        foreach (['metal_storage', 'crystal_storage', 'deuterium_storage'] as $storage) {
            $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($storage);
            \assert(null !== $type);
            $planet->setBuildingLevel($type, 12);
        }
        $planet->storeResources(new Resources(200_000, 200_000, 500_000), $this->clock->now());
        $this->entityManager()->flush();

        return $empire;
    }

    private function build(Empire $founder, int $orbit, string $name = 'Bastion'): AlliancePlanet
    {
        $system = $founder->getHomePlanet()->getSystem();

        return $this->planets()->build($founder, $system->getGalaxy()->getNumber(), $system->getNumber(), $orbit, $name);
    }

    private function freeOrbit(Empire $empire): int
    {
        $system = $empire->getHomePlanet()->getSystem();
        for ($orbit = 1; null !== self::getContainer()->get(PlanetRepository::class)->findOneBy(['system' => $system, 'position.orbit' => $orbit]); ++$orbit);

        return $orbit;
    }

    /** Attaque de la planète, résolue à l'arrivée */
    private function attack(Fleet $raid, Planet $target): void
    {
        $movement = $this->dispatch()->dispatch($raid, [new MissionStep(SpacePosition::planet($target), FleetAction::JoinBattle, 'Bastion')], 100, new Resources(), 0);
        $this->clock->sleep($movement->getDurationSeconds());
        self::assertSame(2, self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId()));
    }

    /** @param callable(): mixed $attempt */
    private function assertRefused(callable $attempt, string $error): void
    {
        try {
            $attempt();
            self::fail('Action acceptée à tort : ' . $error);
        } catch (InvalidAllianceAction $exception) {
            self::assertSame($error, $exception->getMessage());
        }
    }

    /**
     * Flotte au plein, hors de toute planète de son empire (rien à charger au départ).
     *
     * @param array<string, int> $ships
     */
    private function fleet(Empire $empire, Planet $at, array $ships): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $at, $this->clock->now());
        foreach ($ships as $code => $quantity) {
            $fleet->addShips($this->type($code), $quantity);
        }
        $fleet->refuel($fleet->tankCapacity());
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();

        return $fleet;
    }

    private function type(string $code): ShipType
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        \assert($type instanceof ShipType);

        return $type;
    }

    private function planets(): AlliancePlanets
    {
        return self::getContainer()->get(AlliancePlanets::class);
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
