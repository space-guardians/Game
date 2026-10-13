<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Enum\Admin\AdminRole;
use App\Enum\Alliance\AllianceBuildingType;
use App\Enum\Fleet\FleetAction;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\AllianceMemberRepository;
use App\Repository\PlanetRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Alliance\AlliancePlanets;
use App\Service\Alliance\Alliances;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Alliance » (§4.10) : fondation, invitation, acceptation, gestion des rôles, départ, planète d'alliance ;
 * consultation dans le panneau.
 */
final class AllianceTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testFoundInviteAcceptAndManage(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Alliance');
        $this->client->submitForm('Fonder', ['name' => 'Ligue Stellaire', 'tag' => 'lgs', 'description' => 'Entraide']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Alliance [LGS] Ligue Stellaire fondée.');
        self::assertSelectorTextContains('#alliance', 'votre rôle : fondateur');

        $this->client->submitForm('Inviter', ['name' => 'Bob']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#invitations-envoyees', 'Bob');

        $this->client->loginUser($bob->getUser());
        $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('#invitations', '[LGS] Ligue Stellaire');
        $this->client->submitForm('Accepter');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Bienvenue dans l’alliance [LGS] Ligue Stellaire.');
        self::assertSelectorTextContains('#membres', 'Ana');
        self::assertSelectorNotExists('#invite');

        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Nommer diplomate');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Bob est désormais diplomate.');

        $this->client->loginUser($bob->getUser());
        $this->client->request('GET', '/alliance');
        self::assertSelectorExists('#invite');
        $this->client->submitForm('Quitter l’alliance');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Vous avez quitté l’alliance.');
        self::assertNull(self::getContainer()->get(AllianceMemberRepository::class)->findOneByEmpire($bob));
    }

    public function testPactsAreProposedConcludedAndUnbreakable(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Proposer le pacte', ['name' => 'Bob', 'days' => '60']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Pacte de 60 jours proposé à Bob.');
        self::assertSelectorTextContains('#pactes', 'proposé un pacte de 60 jours');

        $this->client->loginUser($bob->getUser());
        $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('#pactes', 'Ana · vous propose un pacte de 60 jours');
        $this->client->submitForm('Conclure le pacte');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Pacte conclu avec Ana');
        self::assertSelectorTextContains('#pactes', 'en vigueur jusqu’au');
        // Conclu : plus de bouton pour le rompre
        self::assertSelectorNotExists('#pactes form');
    }

    public function testFounderSharesCombatReports(): void
    {
        [$ana] = $this->empires('Ana');
        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Fonder', ['name' => 'Ligue', 'tag' => 'LGS']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#partage', 'réservés aux membres engagés');

        $this->client->submitForm('Partager les rapports de combat');
        $this->client->followRedirect();

        self::assertSelectorTextContains('#partage', 'partagés avec toute l’alliance');
    }

    public function testFounderBuildsAnAlliancePlanetThenManagesIt(): void
    {
        $clock = self::mockTime('2026-10-12 10:00:00');
        [$ana] = $this->empires('Ana');
        $home = $ana->getHomePlanet();
        $home->storeResources(new Resources(100_000, 100_000, 100_000), $clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $orbit = 1;
        while (null !== self::getContainer()->get(PlanetRepository::class)->findOneBy(['system' => $home->getSystem(), 'position.orbit' => $orbit])) {
            ++$orbit;
        }
        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Fonder', ['name' => 'Ligue', 'tag' => 'LGS']);
        $this->client->followRedirect();

        $this->client->submitForm('Construire la planète', ['name' => 'Bastion', 'orbit' => (string) $orbit]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Construction de Bastion lancée');
        self::assertSelectorTextContains('#planete-alliance', 'en construction jusqu’au 13/10/2026 10:00');
        self::assertSelectorNotExists('[id^="batiments-"]');

        $clock->sleep(AlliancePlanets::BUILD_HOURS * 3600);
        $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('#planete-alliance', 'intégrité 100 %');
        self::assertSelectorTextContains('[id^="batiments-"]', 'Scanner');
        $this->client->submitForm('Commander', ['quantity' => '2']);
        $this->client->followRedirect();
        // Stock vide : les membres doivent d'abord l'alimenter
        self::assertSelectorTextContains('.sg-alert', 'Stock de la planète d’alliance insuffisant');
    }

    public function testScannerListsForeignFleetsAroundTheAlliancePlanet(): void
    {
        $clock = self::mockTime('2026-10-12 10:00:00');
        [$ana] = $this->empires('Ana');
        $home = $ana->getHomePlanet();
        $home->storeResources(new Resources(100_000, 100_000, 100_000), $clock->now());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
        self::getContainer()->get(Alliances::class)->found($ana, 'Ligue', 'LGS');
        $orbit = 1;
        while (null !== self::getContainer()->get(PlanetRepository::class)->findOneBy(['system' => $home->getSystem(), 'position.orbit' => $orbit])) {
            ++$orbit;
        }
        $alliancePlanet = self::getContainer()->get(AlliancePlanets::class)->build($ana, $home->getSystem()->getGalaxy()->getNumber(), $home->getSystem()->getNumber(), $orbit, 'Bastion');
        $clock->sleep(AlliancePlanets::BUILD_HOURS * 3600);
        $alliancePlanet->getPlanet()->storeResources(new Resources(100_000, 100_000, 100_000), $clock->now());
        $prowler = new Fleet(EmpireFactory::createOne(['name' => 'Rôdeur']), 'Patrouille', $home, $clock->now());
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('light_fighter');
        \assert(null !== $type);
        $prowler->addShips($type, 3);
        $entityManager->persist($prowler);
        $entityManager->flush();

        $this->client->loginUser($ana->getUser());
        $crawler = $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('#planete-alliance', 'Pas de scanner');
        $this->client->submit($crawler->filter('[id^="batiments-"] form')->first()->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.sg-alert', 'Scanner d’alliance porté au niveau 1.');
        self::assertSelectorTextContains('[id^="scanner-"]', 'Rôdeur');
        self::assertSelectorTextContains('[id^="scanner-"]', 'à l’arrêt');
    }

    public function testGatesAreLinkedOnScreenAndAFleetInTransitIsShown(): void
    {
        $clock = self::mockTime('2026-10-13 10:00:00');
        [$ana] = $this->empires('Ana');
        $home = $ana->getHomePlanet();
        $home->storeResources(new Resources(500_000, 500_000, 500_000), $clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::getContainer()->get(Alliances::class)->found($ana, 'Ligue', 'LGS');
        $planets = self::getContainer()->get(AlliancePlanets::class);
        $system = $home->getSystem();
        $gates = [];
        foreach (['Bastion', 'Relais'] as $name) {
            $orbit = 1;
            while (null !== self::getContainer()->get(PlanetRepository::class)->findOneBy(['system' => $system, 'position.orbit' => $orbit])) {
                ++$orbit;
            }
            $gates[] = $planets->build($ana, $system->getGalaxy()->getNumber(), $system->getNumber(), $orbit, $name);
        }
        $clock->sleep(AlliancePlanets::BUILD_HOURS * 3600);
        foreach ($gates as $gate) {
            $gate->getPlanet()->storeResources(new Resources(500_000, 500_000, 500_000), $clock->now());
            $planets->raise($ana, $gate, AllianceBuildingType::TeleportGate);
        }

        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Relier les portes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Portes reliées : Bastion ↔ Relais.');
        self::assertSelectorTextContains(\sprintf('#porte-%d', $gates[0]->getId()), 'Reliée à Relais');

        // Une flotte de l'empire entre par Bastion : l'écran Flotte montre son transit (entités relues après la requête)
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $ana = $entityManager->find(Empire::class, $ana->getId());
        $entry = $entityManager->find(Planet::class, $gates[0]->getPlanet()->getId());
        \assert($ana instanceof Empire && $entry instanceof Planet);
        $fleet = new Fleet($ana, 'Escadre', $ana->getHomePlanet(), $clock->now());
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('cruiser');
        \assert(null !== $type);
        $fleet->addShips($type, 2);
        $fleet->refuel($fleet->tankCapacity());
        $entityManager->persist($fleet);
        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep(SpacePosition::planet($entry), FleetAction::Teleport, 'Bastion')], 100, new Resources(), 0);
        $clock->sleep($movement->getDurationSeconds());
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId());

        $this->client->request('GET', '/flotte');
        self::assertSelectorTextContains('.sg-fleet__movement', 'En transit par la porte de téléportation');
        self::assertSelectorTextContains('.sg-fleet__movement', 'sans demi-tour possible');
    }

    public function testRefusalIsShown(): void
    {
        [$ana] = $this->empires('Ana');
        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');

        $this->client->submitForm('Fonder', ['name' => 'Li', 'tag' => 'LG']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.sg-alert', 'Le nom compte de 3 à 30 caractères.');
    }

    public function testModeratorsCanConsultAlliances(): void
    {
        [$ana] = $this->empires('Ana');
        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Fonder', ['name' => 'Ligue', 'tag' => 'LGS']);

        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');
        $this->client->request('GET', '/admin/alliances');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'LGS');
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(static fn(string $name): Empire => EmpireFactory::createOne(['name' => $name]), array_values($names));
    }
}
