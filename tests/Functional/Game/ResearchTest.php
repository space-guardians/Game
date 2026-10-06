<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Repository\BuildingTypeRepository;
use App\Repository\TechnologyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Recherche » (§4.4) : technologies, prérequis, lancement depuis la planète active, recherche en cours.
 */
final class ResearchTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::mockTime('2026-10-06 10:00:00');
    }

    public function testWithoutLaboratoryEverythingIsLocked(): void
    {
        $this->login(laboratory: 0);

        $crawler = $this->client->request('GET', '/recherche');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Recherche');
        self::assertCount(10, $crawler->filter('.sg-entity'));
        $energy = $crawler->filter('.sg-entity')->first();
        self::assertStringContainsString('Énergie', $energy->text());
        self::assertStringContainsString('Verrouillé', $energy->filter('.sg-chip')->text());
        self::assertStringContainsString('Laboratoire de recherche 1', $energy->filter('.sg-entity__requires')->text());
        self::assertSelectorTextContains('.sg-research-queue', 'Aucune recherche en cours dans l’empire.');
    }

    public function testShowsTechTreeWithStates(): void
    {
        $empire = $this->login(laboratory: 1);
        $energy = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('energy');
        \assert(null !== $energy);
        $empire->setResearchLevel($energy, 1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->client->request('GET', '/recherche');

        self::assertCount(10, $crawler->filter('.sg-techtree .sg-technode'));
        self::assertSelectorTextContains('.sg-technode[data-code="energy"]', 'Acquise');
        self::assertSelectorExists('.sg-technode--acquired[data-code="energy"][href="#techno-energy"]');
        self::assertSelectorExists('.sg-technode--available[data-code="computer"]');
        self::assertSelectorExists('.sg-technode--locked[data-code="weapons"]');
        self::assertSelectorExists('#techno-weapons.sg-entity');
        // Énergie 1 remplit la propulsion à combustion (énergie 1) mais pas le bouclier (énergie 3)
        self::assertSelectorExists('.sg-techtree__link.is-met[data-from="energy"][data-to="combustion_drive"]');
        self::assertSelectorExists('.sg-techtree__link:not(.is-met)[data-from="energy"][data-to="shielding"]');
        // Liens entre technologies seulement : combustion, impulsion, bouclier, hyperespace (2), astrophysique (2)
        self::assertCount(7, $crawler->filter('.sg-techtree__link'));
    }

    public function testResearchingNodeShowsProgress(): void
    {
        $clock = self::mockTime('2026-10-06 10:00:00');
        $this->login(laboratory: 1);
        $crawler = $this->client->request('GET', '/recherche');
        $this->client->submit($crawler->filter('.sg-entity')->first()->selectButton('Rechercher niveau 1')->form());
        $clock->sleep(360);

        $this->client->request('GET', '/recherche');

        self::assertSelectorExists('.sg-technode--researching[data-code="energy"] [aria-valuenow="25"]');
    }

    public function testLaunchesResearchFromActivePlanet(): void
    {
        $this->login(laboratory: 1);
        $crawler = $this->client->request('GET', '/recherche');
        $energy = $crawler->filter('.sg-entity')->first();
        self::assertStringContainsString('Disponible', $energy->filter('.sg-chip')->text());
        self::assertStringContainsString('Niveau 1 : 24 min', $energy->text());

        $this->client->submit($energy->selectButton('Rechercher niveau 1')->form());

        self::assertResponseRedirects('/recherche');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Recherche lancée : Énergie niveau 1.');
        self::assertSelectorTextContains('.sg-research-queue', 'Énergie niv. 1');
        self::assertSelectorTextContains('.sg-research-queue', '10:24:00');
        self::assertStringContainsString('En recherche', $crawler->filter('.sg-entity')->first()->filter('.sg-chip')->text());
        // Une seule recherche à la fois : les autres boutons sont désactivés
        self::assertSelectorTextContains('.sg-entity:nth-child(2) button[disabled]', 'Recherche en cours');
    }

    public function testCancelsResearchWithProrataRefund(): void
    {
        $clock = self::mockTime('2026-10-06 10:00:00');
        $this->login(laboratory: 1);
        $crawler = $this->client->request('GET', '/recherche');
        $this->client->submit($crawler->filter('.sg-entity')->first()->selectButton('Rechercher niveau 1')->form());
        $clock->sleep(360);

        $crawler = $this->client->request('GET', '/recherche');
        self::assertSelectorTextContains('.sg-research-queue', 'environ 75 % maintenant');
        $this->client->submit($crawler->selectButton('Annuler la recherche')->form());

        self::assertResponseRedirects('/recherche');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Recherche annulée : 75 % du coût remboursé sur la planète de lancement.');
        self::assertSelectorTextContains('.sg-research-queue', 'Aucune recherche en cours dans l’empire.');
    }

    public function testCancellingWithoutResearchIsExplained(): void
    {
        $this->login(laboratory: 1);
        $crawler = $this->client->request('GET', '/recherche');
        $this->client->submit($crawler->filter('.sg-entity')->first()->selectButton('Rechercher niveau 1')->form());
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Annuler la recherche')->form();
        $this->client->submit($form);

        // Deuxième envoi (double clic, onglet périmé) : plus rien à annuler
        $this->client->submit($form);

        $this->client->followRedirect();
        self::assertAnySelectorTextContains('.sg-alert', 'Aucune recherche en cours dans l’empire.');
    }

    public function testLockedTechnologyCannotBeResearched(): void
    {
        $this->login(laboratory: 1);
        $crawler = $this->client->request('GET', '/recherche');
        $token = (string) $crawler->filter('.sg-entity')->eq(5)->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/recherche/weapons/lancer', ['_token' => $token]);

        $this->client->followRedirect();
        self::assertAnySelectorTextContains('.sg-alert', 'Armement est verrouillée : elle requiert Laboratoire de recherche niveau 4.');
    }

    public function testInvalidTokenIsRefused(): void
    {
        $this->login(laboratory: 1);

        $this->client->request('POST', '/recherche/energy/lancer', ['_token' => 'invalide']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'La page a expiré');
    }

    private function login(int $laboratory): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => new \DateTimeImmutable('2026-10-06 10:00:00')]);
        $planet = $empire->getHomePlanet();
        $lab = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('research_lab');
        \assert(null !== $lab);
        $planet->setBuildingLevel($lab, $laboratory);
        $planet->storeResources(new Resources(500, 900, 500), new \DateTimeImmutable('2026-10-06 10:00:00'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($empire->getUser());

        return $empire;
    }
}
