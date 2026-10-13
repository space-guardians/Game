<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Alliance;

use App\Entity\AlliancePlanet;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\StarSystem;
use App\Enum\Alliance\AllianceBuildingType;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Fleet\FleetStatus;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetMovementRepository;
use App\Repository\PlanetRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\TeleportLinkRepository;
use App\Service\Alliance\AlliancePlanets;
use App\Service\Alliance\Alliances;
use App\Service\Alliance\AllianceScanner;
use App\Service\Alliance\TeleportGates;
use App\Service\Alliance\TeleportRules;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Portes de téléportation (§4.10) : liaison entre deux portes de l'alliance, transit d'une flotte d'un membre,
 * intouchable et sans demi-tour, puis reprise de ses ordres à la sortie.
 */
final class TeleportGatesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-13 10:00:00');
    }

    public function testFleetEntersAGateAndLeavesByTheLinkedOneThenCarriesOn(): void
    {
        [$founder, $mate, $entry, $exit] = $this->linkedGates();
        $fleet = $this->fleet($mate, $founder->getHomePlanet(), ['cruiser' => 3]);
        $next = $this->freePlanetIn($exit->getPlanet()->getSystem());

        $toGate = $this->dispatch()->dispatch($fleet, [
            new MissionStep(SpacePosition::planet($entry->getPlanet()), FleetAction::Teleport, 'Bastion'),
            new MissionStep(SpacePosition::planet($founder->getHomePlanet()), FleetAction::Station, 'retour'),
        ], 100, new Resources(), 0);
        $this->clock->sleep($toGate->getDurationSeconds());
        $this->resolve((int) $toGate->getEvent()?->getId());

        // Entrée dans la porte : en transit vers la porte reliée, l'ordre reste en cours
        $transit = $this->movementOf($fleet);
        self::assertTrue($transit->isTeleport());
        self::assertSame($exit->getPlanet(), $transit->getTeleportExit());
        self::assertSame(TeleportRules::BASE_TRANSIT_SECONDS, $transit->getDurationSeconds());
        self::assertSame(FleetStatus::InFlight, $fleet->getStatus());
        self::assertSame(FleetOrderStatus::InProgress, $transit->getOrder()->getStatus());
        // Sans demi-tour : impossible de lui donner une autre mission avant la sortie
        try {
            $this->dispatch()->dispatch($fleet, [new MissionStep(SpacePosition::planet($next), FleetAction::Station, 'ailleurs')], 100, new Resources(), 0);
            self::fail('Une flotte en transit a reçu une nouvelle mission.');
        } catch (InvalidFleetMission $exception) {
            self::assertStringContainsString('n’est pas stationnée', $exception->getMessage());
        }
        // Hors de l'espace : aucun scanner ne la voit
        self::assertSame([], self::getContainer()->get(AllianceScanner::class)->contacts($exit));

        $this->clock->sleep(TeleportRules::BASE_TRANSIT_SECONDS);
        $this->resolve((int) $transit->getEvent()?->getId());

        // Sortie sur la planète de la porte reliée, ordre accompli, puis l'ordre suivant part de là
        self::assertSame(FleetOrderStatus::Done, $transit->getOrder()->getStatus());
        $onward = $this->movementOf($fleet);
        self::assertFalse($onward->isTeleport());
        self::assertSame($exit->getPlanet()->getId(), $onward->getOrigin()->planetId);
        self::assertSame('retour', $onward->getOrder()->getDestinationLabel());
    }

    public function testOnlyMembersUseLinkedGates(): void
    {
        [$founder, , $entry, $exit] = $this->linkedGates();
        $outsider = $this->fleet(EmpireFactory::createOne(), $founder->getHomePlanet(), ['cruiser' => 1]);

        $this->assertMissionRefused($outsider, $entry->getPlanet(), 'Ordre 1 : Seules les flottes des membres de l’alliance empruntent ses portes.');

        $gates = self::getContainer()->get(TeleportGates::class);
        $link = self::getContainer()->get(TeleportLinkRepository::class)->findFor($entry);
        \assert(null !== $link);
        $gates->unlink($founder, $link);
        $member = $this->fleet($founder, $founder->getHomePlanet(), ['cruiser' => 1]);
        $this->assertMissionRefused($member, $exit->getPlanet(), 'Ordre 1 : La porte de Relais n’est reliée à aucune autre.');
    }

    public function testLinkRules(): void
    {
        [$founder, $mate, $entry, $exit] = $this->linkedGates(link: false);
        $gates = self::getContainer()->get(TeleportGates::class);
        $third = $this->alliancePlanet($founder, $this->farSystem($founder, 6000), 'Avant-poste', gate: false);

        foreach ([
            [fn() => $gates->link($mate, $entry, $exit), 'Seuls le fondateur et les diplomates relient les portes de l’alliance.'],
            [fn() => $gates->link($founder, $entry, $entry), 'Une porte se relie à une autre porte de la même alliance.'],
            [fn() => $gates->link($founder, $entry, $third), 'Avant-poste n’a pas de porte de téléportation en service.'],
        ] as [$attempt, $error]) {
            try {
                $attempt();
                self::fail('Liaison acceptée à tort : ' . $error);
            } catch (InvalidAllianceAction $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }

        $link = $gates->link($founder, $entry, $exit);
        self::assertSame($exit, $link->exitFor($entry));
        self::assertSame($entry, $link->exitFor($exit));
        $this->expectExceptionObject(new InvalidAllianceAction('La porte de Bastion est déjà reliée.'));
        $gates->link($founder, $entry, $exit);
    }

    /**
     * Alliance « Ligue » (fondateur, camarade) et deux planètes à porte : « Bastion » dans le système du fondateur,
     * « Relais » dans un système lointain de la même galaxie.
     *
     * @return array{Empire, Empire, AlliancePlanet, AlliancePlanet}
     */
    private function linkedGates(bool $link = true): array
    {
        $founder = EmpireFactory::createOne(['name' => 'Fondateur', 'foundedAt' => $this->clock->now()]);
        $mate = EmpireFactory::createOne(['name' => 'Camarade', 'foundedAt' => $this->clock->now()]);
        $alliances = self::getContainer()->get(Alliances::class);
        $alliances->found($founder, 'Ligue', 'LGS');
        $alliances->accept($alliances->invite($founder, 'Camarade'), $mate);
        $founder->getHomePlanet()->storeResources(new Resources(500_000, 500_000, 500_000), $this->clock->now());

        $entry = $this->alliancePlanet($founder, $founder->getHomePlanet()->getSystem(), 'Bastion');
        $exit = $this->alliancePlanet($founder, $this->farSystem($founder, 3000), 'Relais');
        $this->clock->sleep(AlliancePlanets::BUILD_HOURS * 3600);
        foreach ([$entry, $exit] as $gate) {
            $gate->getPlanet()->storeResources(new Resources(1_000_000, 1_000_000, 1_000_000), $this->clock->now());
            self::getContainer()->get(AlliancePlanets::class)->raise($founder, $gate, AllianceBuildingType::TeleportGate);
        }
        if ($link) {
            self::getContainer()->get(TeleportGates::class)->link($founder, $entry, $exit);
        }

        return [$founder, $mate, $entry, $exit];
    }

    private function alliancePlanet(Empire $founder, StarSystem $system, string $name, bool $gate = true): AlliancePlanet
    {
        $planets = self::getContainer()->get(AlliancePlanets::class);
        $alliancePlanet = $planets->build($founder, $system->getGalaxy()->getNumber(), $system->getNumber(), $this->freeOrbit($system), $name);
        if (!$gate) {
            $this->clock->sleep(AlliancePlanets::BUILD_HOURS * 3600);
        }

        return $alliancePlanet;
    }

    private function farSystem(Empire $empire, float $distance): StarSystem
    {
        $home = $empire->getHomePlanet()->getSystem();

        return StarSystemFactory::createOne([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + $distance, $home->getPosition()->y),
        ]);
    }

    private function freeOrbit(StarSystem $system): int
    {
        for ($orbit = 1; null !== self::getContainer()->get(PlanetRepository::class)->findOneBy(['system' => $system, 'position.orbit' => $orbit]); ++$orbit);

        return $orbit;
    }

    /** Planète libre du système, créée sur une orbite libre */
    private function freePlanetIn(StarSystem $system): Planet
    {
        return PlanetFactory::createOne(['system' => $system, 'position' => new OrbitalPosition($this->freeOrbit($system), 50.0, 0.0)]);
    }

    private function assertMissionRefused(Fleet $fleet, Planet $gate, string $error): void
    {
        try {
            $this->dispatch()->dispatch($fleet, [new MissionStep(SpacePosition::planet($gate), FleetAction::Teleport, 'porte')], 100, new Resources(), 0);
            self::fail('Mission acceptée à tort : ' . $error);
        } catch (InvalidFleetMission $exception) {
            self::assertSame($error, $exception->getMessage());
        }
    }

    private function movementOf(Fleet $fleet): FleetMovement
    {
        $movement = self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($fleet);
        \assert(null !== $movement);

        return $movement;
    }

    private function resolve(int $eventId): void
    {
        self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve($eventId));
    }

    /**
     * Flotte au plein, sans rien à charger au départ.
     *
     * @param array<string, int> $ships
     */
    private function fleet(Empire $empire, Planet $at, array $ships): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $at, $this->clock->now());
        foreach ($ships as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $fleet->addShips($type, $quantity);
        }
        $fleet->refuel($fleet->tankCapacity());
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();

        return $fleet;
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
