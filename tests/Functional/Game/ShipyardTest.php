<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\EmpireBlueprint;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Repository\BuildingTypeRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Chantier spatial » (§4.5) : types de vaisseaux, prérequis, commande, file des commandes.
 */
final class ShipyardTest extends WebTestCase
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

    public function testWithoutShipyardEverythingIsLocked(): void
    {
        $this->login(shipyard: 0);

        $crawler = $this->client->request('GET', '/chantier-spatial');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Chantier spatial');
        // 12 vaisseaux, puis 8 défenses dans leur propre section
        self::assertCount(12, $crawler->filter('#vaisseaux .sg-entity'));
        self::assertCount(8, $crawler->filter('#defenses .sg-entity'));
        self::assertCount(20, $crawler->filter('.sg-entity--locked'));
        self::assertSelectorTextContains('#vaisseau-rocket_launcher .sg-entity__requires', 'Chantier spatial 1');
        self::assertSelectorTextNotContains('#vaisseau-rocket_launcher', 'Vitesse');
        self::assertSelectorTextContains('#vaisseau-light_fighter .sg-entity__requires', 'Chantier spatial 1');
        self::assertSelectorTextContains('.sg-shipyard-queue', 'Aucune commande');
    }

    public function testOrdersShipsAndShowsQueue(): void
    {
        $this->login(shipyard: 1);
        $crawler = $this->client->request('GET', '/chantier-spatial');
        $fighter = $crawler->filter('#vaisseau-light_fighter');
        self::assertStringContainsString('Disponible', $fighter->filter('.sg-chip')->text());
        self::assertStringContainsString('À l’unité : 48 min', $fighter->text());
        self::assertStringContainsString('Intercepteur', $fighter->text());

        $this->client->submit($fighter->selectButton('Commander')->form(['quantity' => '2']));
        self::assertResponseRedirects('/chantier-spatial');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Commande passée : 2 × Chasseur léger, livraison à 11:36:00.');

        $this->client->submit($this->client->getCrawler()->filter('#vaisseau-light_fighter')->selectButton('Commander')->form(['quantity' => '1']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-shipyard-queue .sg-queue-item--ship', 'Chasseur léger × 2');
        // Un seul poste : la deuxième commande attend la fin de la première
        self::assertSelectorTextContains('.sg-shipyard-queue .sg-queue-item--waiting', 'début à 11:36:00');
    }

    public function testInvalidQuantityIsRefused(): void
    {
        $this->login(shipyard: 1);
        $crawler = $this->client->request('GET', '/chantier-spatial');
        $token = (string) $crawler->filter('#vaisseau-light_fighter input[name="_token"]')->attr('value');

        $this->client->request('POST', '/chantier-spatial/light_fighter/commander', ['_token' => $token, 'quantity' => '0']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Commandez entre 1 et');
    }

    public function testReservedShipShowsItsBlueprintAndAntimatterCost(): void
    {
        $empire = $this->login(shipyard: 1);

        $this->client->request('GET', '/chantier-spatial');
        self::assertSelectorTextContains('#vaisseau-antimatter_cruiser', 'Antimatière : 30 (manque 30)');
        self::assertSelectorTextContains('#plan-antimatter_cruiser', 'Requiert le plan de ce vaisseau, à remporter en battant une civilisation « boss ».');
        // Ressource rare absente de la barre tant que la planète n'en a pas
        self::assertSelectorNotExists('.sg-topbar .sg-resource--antimatter');

        // Plan et antimatière remportés sur un boss (§4.14)
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $cruiser = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('antimatter_cruiser');
        \assert(null !== $cruiser);
        $entityManager->persist(new EmpireBlueprint($empire, $cruiser, new \DateTimeImmutable('2026-10-06 10:00:00')));
        $empire->getHomePlanet()->addAntimatter(12);
        $entityManager->flush();

        $this->client->request('GET', '/chantier-spatial');
        self::assertSelectorNotExists('#plan-antimatter_cruiser');
        self::assertSelectorTextContains('#vaisseau-antimatter_cruiser', 'Antimatière : 30 (manque 18)');
        self::assertSelectorTextContains('.sg-topbar .sg-resource--antimatter', 'Antimatière 12');
    }

    public function testLockedShipCannotBeOrdered(): void
    {
        $this->login(shipyard: 1);
        $crawler = $this->client->request('GET', '/chantier-spatial');
        $token = (string) $crawler->filter('#vaisseau-cruiser input[name="_token"]')->attr('value');

        $this->client->request('POST', '/chantier-spatial/cruiser/commander', ['_token' => $token, 'quantity' => '1']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Croiseur est verrouillé');
    }

    private function login(int $shipyard): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => new \DateTimeImmutable('2026-10-06 10:00:00')]);
        $planet = $empire->getHomePlanet();
        $buildings = self::getContainer()->get(BuildingTypeRepository::class);
        $shipyardType = $buildings->findOneByCode('shipyard');
        \assert(null !== $shipyardType);
        $planet->setBuildingLevel($shipyardType, $shipyard);
        $planet->storeResources(new Resources(10_000, 10_000, 1_000), new \DateTimeImmutable('2026-10-06 10:00:00'));
        foreach (['metal_storage', 'crystal_storage'] as $storage) {
            $type = $buildings->findOneByCode($storage);
            \assert(null !== $type);
            $planet->setBuildingLevel($type, 5);
        }
        $combustion = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('combustion_drive');
        \assert(null !== $combustion);
        $empire->setResearchLevel($combustion, 1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($empire->getUser());

        return $empire;
    }
}
