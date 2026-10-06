<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetRepository;
use App\Repository\ShipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Flotte » (§4.5) : hangar de la planète active, constitution et dissolution d'une flotte.
 */
final class FleetTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testEmptyHangarPointsToShipyard(): void
    {
        $this->login();

        $this->client->request('GET', '/flotte');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Flotte');
        self::assertSelectorTextContains('main', 'Aucun vaisseau en attente sur cette planète.');
        self::assertSelectorTextContains('main', 'Aucune flotte constituée.');
    }

    public function testAssemblesFleetFromHangarThenDisbandsIt(): void
    {
        $empire = $this->login(['light_fighter' => 10, 'small_cargo' => 3]);

        $crawler = $this->client->request('GET', '/flotte');
        self::assertSelectorTextContains('#hangar', 'Chasseur léger');
        self::assertSelectorTextContains('#hangar', 'Intercepteur');
        $this->client->submit($crawler->selectButton('Constituer la flotte')->form([
            'name' => 'Escadre Orion',
            'ships[light_fighter]' => '6',
            'ships[small_cargo]' => '2',
        ]));

        self::assertResponseRedirects('/flotte');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Flotte « Escadre Orion » constituée : 8 vaisseau(x).');
        self::assertSelectorTextContains('#flottes', 'Escadre Orion');
        self::assertSelectorTextContains('#flottes', '6 × Chasseur léger');
        // Cargo : 6 × 50 + 2 × 5 000 ; vitesse du plus lent (transporteur léger)
        self::assertMatchesRegularExpression('/Cargo 10\D300 · Vitesse 5\D000/u', $this->client->getCrawler()->filter('#flottes')->text());
        self::assertSame(4, $this->planet($empire)->shipCount($this->ship('light_fighter')));

        $this->client->submit($this->client->getCrawler()->selectButton('Dissoudre')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Flotte « Escadre Orion » dissoute');
        self::assertSame(10, $this->planet($empire)->shipCount($this->ship('light_fighter')));
        self::assertSame([], self::getContainer()->get(FleetRepository::class)->findAll());
    }

    public function testCannotTakeMoreShipsThanTheHangarHolds(): void
    {
        $this->login(['light_fighter' => 2]);
        $crawler = $this->client->request('GET', '/flotte');
        $token = (string) $crawler->filter('.sg-fleet-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/flotte/constituer', ['_token' => $token, 'ships' => ['light_fighter' => '5']]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Chasseur léger : 2 disponible(s), 5 demandé(s).');
        self::assertSame([], self::getContainer()->get(FleetRepository::class)->findAll());
    }

    public function testEmptySelectionIsRefusedAndDefaultNameIsGiven(): void
    {
        $this->login(['light_fighter' => 2]);
        $crawler = $this->client->request('GET', '/flotte');
        $token = (string) $crawler->filter('.sg-fleet-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/flotte/constituer', ['_token' => $token, 'ships' => ['light_fighter' => '0']]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Choisissez au moins un vaisseau.');

        $this->client->request('POST', '/flotte/constituer', ['_token' => $token, 'name' => '', 'ships' => ['light_fighter' => '1']]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Flotte « Flotte 1 » constituée');
    }

    public function testDispatchesFleetWithSuggestedReturnOrder(): void
    {
        $empire = $this->login(['light_fighter' => 4]);
        $home = $empire->getHomePlanet();
        $fleet = new Fleet($empire, 'Escadre Orion', $home, new \DateTimeImmutable());
        $fleet->addShips($this->ship('light_fighter'), 4);
        $fleet->refuel(1000);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();
        $address = $home->getAddress();

        $crawler = $this->client->request('GET', \sprintf('/flotte/%d/envoyer', $fleet->getId()));
        self::assertResponseIsSuccessful();
        // Dernier ordre prérempli : stationner au point de départ (§4.6)
        self::assertSame((string) $address->system, $crawler->filter('input[name="steps[2][system]"]')->attr('value'));
        self::assertSame('station', $crawler->filter('select[name="steps[2][action]"] option[selected]')->attr('value'));

        $this->client->submit($crawler->selectButton('Envoyer la flotte')->form([
            'steps[0][galaxy]' => (string) $address->galaxy,
            'steps[0][system]' => (string) $address->system,
            'steps[0][position]' => '',
            'steps[0][action]' => 'station',
            'speed' => '50',
        ]));

        self::assertResponseRedirects('/flotte');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Flotte « Escadre Orion » en route vers système');
        self::assertSelectorTextContains('#flottes', 'En vol');
        self::assertSelectorTextContains('#flottes .sg-fleet__orders', 'Stationner');
        self::assertSelectorTextContains('#flottes .sg-fleet__orders', 'En cours');
        self::assertSelectorNotExists('#flottes form[action$="/dissoudre"]');
    }

    public function testFleetAtSystemLevelIsSuggestedToReturnToItsSystem(): void
    {
        $empire = $this->login();
        $system = $empire->getHomePlanet()->getSystem();
        $fleet = new Fleet($empire, 'Garde du système', $empire->getHomePlanet(), new \DateTimeImmutable());
        $fleet->addShips($this->ship('light_fighter'), 2);
        // Stationnée au niveau du système (§4.6.2), après une mission
        $fleet->arriveAt(SpaceLocation::of(SpacePosition::system($system)), null);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/flotte/%d/envoyer', $fleet->getId()));

        self::assertSelectorTextContains('main', \sprintf('Stationnée en système %d:%d', $system->getGalaxy()->getNumber(), $system->getNumber()));
        self::assertSame((string) $system->getNumber(), $crawler->filter('input[name="steps[2][system]"]')->attr('value'));
        self::assertSame('', $crawler->filter('input[name="steps[2][position]"]')->attr('value'));
        self::assertSame('station', $crawler->filter('select[name="steps[2][action]"] option[selected]')->attr('value'));
    }

    public function testSuggestedReturnCanBeRemoved(): void
    {
        $empire = $this->login();
        $home = $empire->getHomePlanet();
        $fleet = new Fleet($empire, 'Escadre', $home, new \DateTimeImmutable());
        $fleet->addShips($this->ship('light_fighter'), 1);
        $fleet->refuel(400);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();
        $address = $home->getAddress();

        $crawler = $this->client->request('GET', \sprintf('/flotte/%d/envoyer', $fleet->getId()));
        $this->client->submit($crawler->selectButton('Envoyer la flotte')->form([
            'steps[0][galaxy]' => (string) $address->galaxy,
            'steps[0][system]' => (string) $address->system,
            'steps[0][position]' => '',
            'steps[0][action]' => 'station',
            'steps[2][action]' => '',
        ]));

        self::assertResponseRedirects('/flotte');
        $this->client->followRedirect();
        // Un seul ordre : pas de retour implicite, la flotte restera au niveau du système
        self::assertSelectorCount(1, '#flottes .sg-fleet__order');
    }

    public function testStrandedFleetCanBeRescuedByAnotherFleet(): void
    {
        $empire = $this->login();
        $home = $empire->getHomePlanet();
        $home->storeResources(new Resources(1000, 1000, 5000), new \DateTimeImmutable());
        $stranded = new Fleet($empire, 'Égarée', $home, new \DateTimeImmutable());
        $stranded->addShips($this->ship('light_fighter'), 2);
        $stranded->strand(SpaceLocation::of(SpacePosition::deepSpace((int) $home->getSystem()->getGalaxy()->getId(), new GlobalPosition(0, 0))));
        $rescuer = new Fleet($empire, 'Citerne', $home, new \DateTimeImmutable());
        $rescuer->addShips($this->ship('small_cargo'), 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($stranded);
        $entityManager->persist($rescuer);
        $entityManager->flush();

        $crawler = $this->client->request('GET', '/flotte');
        self::assertSelectorTextContains(\sprintf('#flotte-%d', $stranded->getId()), 'En panne de carburant');
        $this->client->click($crawler->selectLink('Ravitailler avec « Citerne »')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#ordre-ravitaillement', 'Flotte « Égarée », en panne');
        // Plein proposé : réservoirs de la citerne (400), dans la limite du deutérium de la planète
        self::assertSame('400', $this->client->getCrawler()->filter('#carburant')->attr('value'));

        $this->client->submit($this->client->getCrawler()->selectButton('Envoyer la flotte')->form([
            'cargo[deuterium]' => '500',
            'steps[2][action]' => '',
        ]));
        self::assertResponseRedirects('/flotte');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Flotte « Citerne » en route vers flotte « Égarée »');
    }

    public function testUnknownCoordinatesAreRefused(): void
    {
        $empire = $this->login(['light_fighter' => 1]);
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), new \DateTimeImmutable());
        $fleet->addShips($this->ship('light_fighter'), 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/flotte/%d/envoyer', $fleet->getId()));
        $this->client->submit($crawler->selectButton('Envoyer la flotte')->form([
            'steps[0][galaxy]' => '999999',
            'steps[0][system]' => '1',
            'steps[0][action]' => 'station',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-alert', 'Ordre 1 : ces coordonnées ne désignent ni une planète ni un système.');
    }

    public function testAssembledFleetGetsDefaultFormationThatCanBeRearranged(): void
    {
        $this->login(['light_fighter' => 6, 'small_cargo' => 2]);
        $crawler = $this->client->request('GET', '/flotte');
        $this->client->submit($crawler->selectButton('Constituer la flotte')->form([
            'name' => 'Escadre',
            'ships[light_fighter]' => '6',
            'ships[small_cargo]' => '2',
        ]));
        $fleet = self::getContainer()->get(FleetRepository::class)->findOneBy(['name' => 'Escadre']);
        \assert($fleet instanceof Fleet);

        // Par défaut : militaires devant au centre, civils derrière au centre
        $crawler = $this->client->request('GET', \sprintf('/flotte/%d/formation', $fleet->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#grille [data-cell="front-center"]', '6');
        self::assertSelectorTextContains('#grille [data-cell="back-center"]', '2');

        $this->client->submit($crawler->selectButton('Enregistrer la formation')->form([
            'cells[light_fighter][front-center]' => '',
            'cells[light_fighter][front-left]' => '3',
            'cells[light_fighter][front-right]' => '3',
        ]));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Formation de « Escadre » enregistrée.');
        self::assertSelectorTextContains('#grille [data-cell="front-left"]', '3');
        self::assertSelectorTextContains('#grille [data-cell="front-right"]', '3');
        self::assertSelectorTextContains('#grille [data-cell="front-center"]', '·');
    }

    public function testFormationMustPlaceEveryShip(): void
    {
        $this->login(['light_fighter' => 6]);
        $crawler = $this->client->request('GET', '/flotte');
        $this->client->submit($crawler->selectButton('Constituer la flotte')->form(['name' => 'Escadre', 'ships[light_fighter]' => '6']));
        $fleet = self::getContainer()->get(FleetRepository::class)->findOneBy(['name' => 'Escadre']);
        \assert($fleet instanceof Fleet);

        $crawler = $this->client->request('GET', \sprintf('/flotte/%d/formation', $fleet->getId()));
        $this->client->submit($crawler->selectButton('Enregistrer la formation')->form(['cells[light_fighter][front-center]' => '4']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-alert', 'Chasseur léger : 4 placé(s) sur 6.');
        // La saisie est conservée pour correction
        self::assertSame('4', $this->client->getCrawler()->filter('input[name="cells[light_fighter][front-center]"]')->attr('value'));
    }

    public function testCannotDisbandAnotherEmpiresFleet(): void
    {
        $other = EmpireFactory::createOne();
        $fleet = new Fleet($other, 'Étrangère', $other->getHomePlanet(), new \DateTimeImmutable());
        $fleet->addShips($this->ship('light_fighter'), 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();
        $this->login();

        $this->client->request('POST', \sprintf('/flotte/%d/dissoudre', $fleet->getId()), ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, int> $ships vaisseaux au hangar de la planète mère */
    private function login(array $ships = []): Empire
    {
        $empire = EmpireFactory::createOne();
        foreach ($ships as $code => $quantity) {
            $empire->getHomePlanet()->addShips($this->ship($code), $quantity);
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($empire->getUser());

        return $empire;
    }

    private function planet(Empire $empire): Planet
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $planet = $entityManager->find(Planet::class, $empire->getHomePlanet()->getId());
        \assert($planet instanceof Planet);

        return $planet;
    }

    private function ship(string $code): ShipType
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }
}
