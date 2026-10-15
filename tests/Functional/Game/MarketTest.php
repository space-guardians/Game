<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\MarketListing;
use App\Entity\Merchant;
use App\Entity\Planet;
use App\Entity\PlanetListing;
use App\Entity\StarSystem;
use App\Enum\Market\MarketListingStatus;
use App\Enum\Market\PlanetListingStatus;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Model\Economy\Resources;
use App\Repository\MarketListingRepository;
use App\Repository\MerchantHoldingRepository;
use App\Repository\PlanetListingRepository;
use App\Repository\TechnologyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Marché » (§4.15, §5.4) : annonces, publication, transactions en cours, historique.
 */
final class MarketTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testResourceListingIsPublishedAcceptedAndFollowedByBothParties(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $merchant = $this->merchantIn($ana->getHomePlanet()->getSystem()->getId());
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $this->client->loginUser($ana->getUser());

        $crawler = $this->client->request('GET', '/marche');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Marché');
        self::assertSelectorTextContains('#annonces, .sg-empty', 'Aucune annonce ouverte.');
        $this->client->submit($crawler->filter('#publier-ressources')->form([
            'merchant' => (string) $merchant->getId(),
            'offered[metal]' => '900',
            'asked[crystal]' => '400',
        ]));
        self::assertResponseRedirects('/marche');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Annonce publiée chez Comptoir');
        self::assertSelectorTextContains('#annonces', 'Votre annonce');
        self::assertSelectorTextContains('#mes-transactions', 'À livrer : 900 métal.');

        // L'acheteur s'engage, puis voit ce qu'il doit livrer
        $this->client->loginUser($this->reload($bob)->getUser());
        $crawler = $this->client->request('GET', '/marche');
        $listing = self::getContainer()->get(MarketListingRepository::class)->findOneBy([]);
        \assert($listing instanceof MarketListing);
        $this->client->submit($crawler->filter(\sprintf('#annonce-%d', $listing->getId()))->selectButton('Accepter')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Engagement pris : livrez 400 cristal');
        self::assertSelectorTextContains('#mes-transactions', 'En attente des livraisons');
        self::assertSelectorTextContains('#mes-transactions', 'Ana : pas encore livré.');
        self::assertSelectorTextContains('#annonces, .sg-empty', 'Aucune annonce ouverte.');
    }

    public function testHoldingsAndHistoryAreShown(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $merchant = $this->merchantIn($ana->getHomePlanet()->getSystem()->getId());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $listing = new MarketListing($ana, $merchant, new Resources(10.0), new Resources(5.0), new \DateTimeImmutable('2026-10-15 09:00'));
        $listing->accept($bob, new \DateTimeImmutable('2026-10-15 09:10'));
        $listing->markDelivered($ana);
        $listing->markDelivered($bob);
        $listing->complete(new \DateTimeImmutable('2026-10-15 09:30'));
        $entityManager->persist($listing);
        self::getContainer()->get(MerchantHoldingRepository::class)->forEmpire($merchant, $ana)->credit(new Resources(5.0));
        $entityManager->flush();
        $this->client->loginUser($ana->getUser());

        $this->client->request('GET', '/marche');

        self::assertSelectorTextContains('#ressources-gardees', 'Comptoir');
        self::assertSelectorTextContains('#ressources-gardees', '5 métal');
        self::assertSelectorTextContains('#historique', 'Vente chez Comptoir');
        self::assertSelectorTextContains('#historique', 'Validée');
    }

    public function testAColonyIsSoldThroughTheScreen(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $colony = PlanetFactory::createOne();
        $colony->assignTo($ana);
        $merchant = $this->merchantIn($ana->getHomePlanet()->getSystem()->getId());
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $bob->getHomePlanet()->storeResources(new Resources(5000.0, 5000.0, 5000.0), new \DateTimeImmutable());
        $astrophysics = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('astrophysics');
        \assert(null !== $astrophysics);
        $bob->setResearchLevel($astrophysics, 1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($ana->getUser());

        $crawler = $this->client->request('GET', '/marche');
        $this->client->submit($crawler->filter('#publier-planete')->form([
            'planet' => (string) $colony->getId(),
            'kind' => 'sale',
            'merchant' => (string) $merchant->getId(),
            'price[metal]' => '800',
        ]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'est sur le marché : elle est gelée');
        self::assertSelectorTextContains('#mes-transactions', 'vente pour 800 métal');

        $this->client->loginUser($this->reload($bob)->getUser());
        $crawler = $this->client->request('GET', '/marche');
        $listing = self::getContainer()->get(PlanetListingRepository::class)->findOneBy([]);
        \assert($listing instanceof PlanetListing);
        $this->client->submit($crawler->filter(\sprintf('#planete-%d', $listing->getId()))->selectButton('Acheter')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.sg-alert', 'vous appartient désormais.');
        $listing = self::getContainer()->get(PlanetListingRepository::class)->find($listing->getId());
        self::assertSame(PlanetListingStatus::Completed, $listing?->getStatus());
        self::assertSame('Bob', self::getContainer()->get(EntityManagerInterface::class)->find(Planet::class, $colony->getId())?->getOwner()?->getName());
        self::assertSelectorTextContains('#historique', 'vente avec Ana');
    }

    public function testActionsRequireAValidToken(): void
    {
        $ana = EmpireFactory::createOne();
        $this->client->loginUser($ana->getUser());

        $this->client->request('POST', '/marche/annonces', ['_token' => 'faux']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.sg-alert', 'La page a expiré : recommencez.');
        self::assertSame(0, self::getContainer()->get(MarketListingRepository::class)->count(['status' => MarketListingStatus::Open]));
    }

    private function merchantIn(?int $systemId): Merchant
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $merchant = new Merchant($entityManager->getReference(StarSystem::class, $systemId), 'Comptoir', 3.0, -2.0);
        $entityManager->persist($merchant);
        $entityManager->flush();

        return $merchant;
    }

    private function reload(Empire $empire): Empire
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(Empire::class, $empire->getId());
        \assert($found instanceof Empire);

        return $found;
    }
}
