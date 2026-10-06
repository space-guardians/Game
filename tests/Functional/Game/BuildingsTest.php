<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Factory\EmpireFactory;
use App\Repository\BuildingQueueItemRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Bâtiments » (§5.4) et lancement d'une construction (§4.3).
 */
final class BuildingsTest extends WebTestCase
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

    public function testListsBuildingsWithNextLevelCostAndDuration(): void
    {
        $this->login();

        $crawler = $this->client->request('GET', '/batiments');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Bâtiments');
        self::assertCount(10, $crawler->filter('.sg-entity'));
        $mine = $crawler->filter('.sg-entity')->first();
        self::assertStringContainsString('Mine de métal', $mine->text());
        self::assertStringContainsString('Niveau 1 : 1 min 48 s', $mine->text());
        self::assertStringContainsString('Disponible', $mine->text());
        // Centrale à fusion : 900 métal, 360 cristal, 180 deutérium pour 500 / 500 / 0
        $fusion = $crawler->filter('.sg-entity')->eq(4);
        self::assertStringContainsString('Ressources insuffisantes', $fusion->text());
        self::assertStringContainsString('manque 400', $fusion->text());
        self::assertCount(1, $fusion->filter('button[disabled]'));
    }

    public function testLaunchesConstruction(): void
    {
        $empire = $this->login();
        $crawler = $this->client->request('GET', '/batiments');

        $this->client->submit($crawler->filter('.sg-entity')->first()->selectButton('Construire niveau 1')->form());

        self::assertResponseRedirects('/batiments');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Construction lancée : Mine de métal niveau 1.');
        self::assertStringContainsString('En construction', $crawler->filter('.sg-entity')->first()->text());
        self::assertSelectorTextContains('.sg-topbar .sg-resource--metal', '440');
        self::assertNotNull(self::getContainer()->get(BuildingQueueItemRepository::class)->findActiveFor($empire->getHomePlanet()));
    }

    public function testCancelsConstructionFromItsCard(): void
    {
        $empire = $this->login();
        $crawler = $this->client->request('GET', '/batiments');
        $crawler = $this->client->submit($crawler->filter('.sg-entity')->first()->selectButton('Construire niveau 1')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('au prorata du temps restant (environ 100 % maintenant)', $crawler->filter('.sg-entity--building')->text());

        $this->client->submit($crawler->selectButton('Annuler la construction')->form());

        $this->client->followRedirect();
        self::assertAnySelectorTextContains('.sg-alert', 'Construction annulée : 100 % du coût remboursé.');
        self::assertSelectorTextContains('.sg-topbar .sg-resource--metal', '500');
        self::assertNull(self::getContainer()->get(BuildingQueueItemRepository::class)->findActiveFor($empire->getHomePlanet()));
    }

    public function testCancellingWithoutConstructionIsExplained(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/batiments');
        $crawler = $this->client->submit($crawler->filter('.sg-entity')->first()->selectButton('Construire niveau 1')->form());
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Annuler la construction')->form();
        $this->client->submit($form);

        // Deuxième envoi (double clic, onglet périmé) : plus rien à annuler
        $this->client->submit($form);

        $this->client->followRedirect();
        self::assertAnySelectorTextContains('.sg-alert', 'Aucune construction en cours sur cette planète.');
    }

    public function testSecondConstructionIsRefused(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/batiments');
        $token = (string) $crawler->filter('.sg-entity')->eq(1)->filter('input[name="_token"]')->attr('value');
        $this->client->submit($crawler->filter('.sg-entity')->first()->selectButton('Construire niveau 1')->form());

        $this->client->request('POST', '/batiments/crystal_mine/construire', ['_token' => $token]);

        $this->client->followRedirect();
        self::assertAnySelectorTextContains('.sg-alert', 'Une seule construction à la fois');
    }

    public function testRefusesExpiredForm(): void
    {
        $empire = $this->login();

        $this->client->request('POST', '/batiments/metal_mine/construire', ['_token' => 'invalide']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'La page a expiré');
        self::assertNull(self::getContainer()->get(BuildingQueueItemRepository::class)->findActiveFor($empire->getHomePlanet()));
    }

    private function login(): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => new \DateTimeImmutable('2026-10-06 10:00:00')]);
        $this->client->loginUser($empire->getUser());

        return $empire;
    }
}
