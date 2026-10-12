<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Alliance;

use App\Entity\AlliancePlanet;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Enum\Alliance\AllianceBuildingType;
use App\Enum\Fleet\FleetAction;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\PlanetRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Alliance\AlliancePlanets;
use App\Service\Alliance\Alliances;
use App\Service\Alliance\AllianceScanner;
use App\Service\Alliance\ScannerAlerts;
use App\Service\Alliance\ScannerRules;
use App\Service\Fleet\FleetDispatch;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenstruck\Foundry\Test\Factories;

/**
 * Scanner d'alliance (§4.10) : flottes des autres empires repérées à portée, à l'arrêt ou en vol, et alerte des
 * membres au départ d'un trajet qui passe à portée.
 */
final class AllianceScannerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-12 10:00:00');
    }

    public function testStoppedForeignFleetsInRangeAreListed(): void
    {
        [$founder, $mate, $alliancePlanet] = $this->scanningAlliance();
        $home = $founder->getHomePlanet();
        $this->fleet(EmpireFactory::createOne(['name' => 'Rôdeur']), $home, ['light_fighter' => 3], 'Patrouille');
        // Ni les flottes des membres, ni celles qui sont hors de portée
        $this->fleet($mate, $home, ['cruiser' => 2]);
        $this->fleet(EmpireFactory::createOne(), $this->farPlanet($home, 3000), ['cruiser' => 2]);

        $contacts = $this->scanner()->contacts($alliancePlanet);

        self::assertCount(1, $contacts);
        self::assertSame(['Rôdeur', 'Patrouille', 3, false], [$contacts[0]->empire, $contacts[0]->fleet, $contacts[0]->ships, $contacts[0]->inFlight]);
        self::assertLessThan(ScannerRules::RANGE_PER_LEVEL, $contacts[0]->distance);
    }

    public function testFleetHeadingIntoRangeAlertsEveryMemberAndIsTrackedInFlight(): void
    {
        [$founder, $mate, $alliancePlanet] = $this->scanningAlliance();
        $raid = $this->fleet(EmpireFactory::createOne(['name' => 'Rôdeur']), $this->farPlanet($founder->getHomePlanet(), 3000), ['cruiser' => 4]);

        $movement = $this->send($raid, $founder->getHomePlanet());
        self::getContainer()->get(ScannerAlerts::class)->notify();

        $alerts = array_values(array_filter(
            self::getContainer()->get(PublishedUpdates::class)->all(),
            static fn($update): bool => str_contains($update->getData(), 'Scanner de Bastion'),
        ));
        self::assertEqualsCanonicalizing(['/empire/' . $founder->getId(), '/empire/' . $mate->getId()], array_map(static fn($update): string => $update->getTopics()[0], $alerts));
        self::assertStringContainsString('flotte de Rôdeur (4 vaisseaux) en route vers', $alerts[0]->getData());

        // Encore loin : hors de portée ; à l'approche, suivie en vol avec sa destination
        $scanner = $this->scanner();
        self::assertCount(0, $scanner->contacts($alliancePlanet));
        $this->clock->sleep($movement->getDurationSeconds() - 1);
        $contacts = $this->scanner()->contacts($alliancePlanet);
        self::assertCount(1, $contacts);
        self::assertTrue($contacts[0]->inFlight);
        self::assertSame((string) $founder->getHomePlanet()->getAddress(), $contacts[0]->destination);
        self::assertEquals($movement->getArrivesAt(), $contacts[0]->arrivesAt);
    }

    public function testTripsFarFromTheScannerOrByMembersRaiseNoAlert(): void
    {
        [$founder, $mate] = $this->scanningAlliance();
        $far = $this->farPlanet($founder->getHomePlanet(), 3000);
        $farther = $this->farPlanet($founder->getHomePlanet(), 6000);

        $this->send($this->fleet(EmpireFactory::createOne(), $far, ['cruiser' => 1]), $farther);
        $this->send($this->fleet($mate, $far, ['cruiser' => 1]), $founder->getHomePlanet());

        self::assertFalse(self::getContainer()->get(ScannerAlerts::class)->hasPending());
    }

    public function testAlertsLeaveWithTheResponseAndAreDroppedOnFailure(): void
    {
        [$founder] = $this->scanningAlliance();
        $far = $this->farPlanet($founder->getHomePlanet(), 3000);
        $kernel = self::$kernel;
        \assert(null !== $kernel);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $published = static fn(): int => \count(array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => str_contains($update->getData(), 'Scanner de Bastion')));

        // Requête en échec : l'alerte est oubliée
        $this->send($this->fleet(EmpireFactory::createOne(), $far, ['cruiser' => 1]), $founder->getHomePlanet());
        $dispatcher->dispatch(new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException()), KernelEvents::EXCEPTION);
        $dispatcher->dispatch(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new Response()), KernelEvents::RESPONSE);
        self::assertSame(0, $published());

        // Envoi validé : publiée avec la réponse, à chacun des deux membres
        $this->send($this->fleet(EmpireFactory::createOne(), $far, ['cruiser' => 1]), $founder->getHomePlanet());
        $dispatcher->dispatch(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new Response()), KernelEvents::RESPONSE);
        self::assertSame(2, $published());
    }

    public function testNoScannerNoDetection(): void
    {
        [$founder, , $alliancePlanet] = $this->scanningAlliance(scannerLevel: 0);
        $this->fleet(EmpireFactory::createOne(), $founder->getHomePlanet(), ['light_fighter' => 3]);

        self::assertSame(0.0, $this->scanner()->range($alliancePlanet));
        self::assertSame([], $this->scanner()->contacts($alliancePlanet));
    }

    /**
     * Alliance « Ligue » (fondateur, camarade) et sa planète « Bastion » achevée, dans le système du fondateur.
     *
     * @return array{Empire, Empire, AlliancePlanet}
     */
    private function scanningAlliance(int $scannerLevel = 1): array
    {
        $founder = EmpireFactory::createOne(['name' => 'Fondateur', 'foundedAt' => $this->clock->now()]);
        $mate = EmpireFactory::createOne(['name' => 'Camarade', 'foundedAt' => $this->clock->now()]);
        $alliances = self::getContainer()->get(Alliances::class);
        $alliances->found($founder, 'Ligue', 'LGS');
        $alliances->accept($alliances->invite($founder, 'Camarade'), $mate);

        $home = $founder->getHomePlanet();
        $home->storeResources(new Resources(100_000, 100_000, 100_000), $this->clock->now());
        $system = $home->getSystem();
        $orbit = 1;
        while (null !== self::getContainer()->get(PlanetRepository::class)->findOneBy(['system' => $system, 'position.orbit' => $orbit])) {
            ++$orbit;
        }
        $planets = self::getContainer()->get(AlliancePlanets::class);
        $alliancePlanet = $planets->build($founder, $system->getGalaxy()->getNumber(), $system->getNumber(), $orbit, 'Bastion');
        $this->clock->sleep(AlliancePlanets::BUILD_HOURS * 3600);
        $alliancePlanet->getPlanet()->storeResources(new Resources(1_000_000, 1_000_000, 1_000_000), $this->clock->now());
        for ($level = 0; $level < $scannerLevel; ++$level) {
            $planets->raise($founder, $alliancePlanet, AllianceBuildingType::Scanner);
        }
        $this->entityManager()->flush();

        return [$founder, $mate, $alliancePlanet];
    }

    /** Planète d'un autre système de la même galaxie, à la distance donnée du système de $near */
    private function farPlanet(Planet $near, float $distance): Planet
    {
        $system = $near->getSystem();

        return PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $system->getGalaxy(),
            'position' => new GlobalPosition($system->getPosition()->x + $distance, $system->getPosition()->y),
        ])]);
    }

    private function send(Fleet $fleet, Planet $destination): FleetMovement
    {
        return self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep(SpacePosition::planet($destination), FleetAction::Station, (string) $destination->getAddress())], 100, new Resources(), 0);
    }

    /**
     * Flotte au plein, sans rien à charger au départ.
     *
     * @param array<string, int> $ships
     */
    private function fleet(Empire $empire, Planet $at, array $ships, string $name = 'Escadre'): Fleet
    {
        $fleet = new Fleet($empire, $name, $at, $this->clock->now());
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

    private function scanner(): AllianceScanner
    {
        return self::getContainer()->get(AllianceScanner::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
