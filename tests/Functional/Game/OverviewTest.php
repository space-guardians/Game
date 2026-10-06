<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\Planet;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\UserFactory;
use App\Repository\EmpireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Vue d'ensemble de la planète active et sélecteur de planète (§5.4).
 */
final class OverviewTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testShowsActivePlanetAndEmpire(): void
    {
        $empire = EmpireFactory::createOne(['name' => 'Ordre d’Orion', 'homePlanet' => PlanetFactory::new(['temperature' => -42])]);
        $this->client->loginUser($empire->getUser());

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Planète mère');
        self::assertSelectorTextContains('.sg-topbar', 'Ordre d’Orion');
        self::assertSelectorTextContains('.sg-topbar', (string) $empire->getHomePlanet());
        self::assertSelectorTextContains('.sg-overview', '−42 °C');
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Vue d’ensemble');
        self::assertSelectorTextContains('.sg-sidenav [aria-current="true"]', (string) $empire->getHomePlanet());
    }

    public function testShowsResourcesWithLiveCounter(): void
    {
        $clock = self::mockTime('2026-10-06 10:00:00');
        $empire = EmpireFactory::createOne(['foundedAt' => $clock->now()]);
        $clock->sleep(2 * 3600);
        $this->client->loginUser($empire->getUser());

        $crawler = $this->client->request('GET', '/');

        $metal = $crawler->filter('.sg-topbar .sg-resource--metal');
        self::assertSame('560', $metal->attr('data-resource-counter-amount-value'));
        self::assertSame('30', $metal->attr('data-resource-counter-rate-value'));
        self::assertSame('10000', $metal->attr('data-resource-counter-capacity-value'));
        self::assertSelectorTextContains('.sg-topbar .sg-resource--crystal', '530');
        self::assertSelectorTextContains('.sg-overview .sg-table', '+15/h');
        self::assertSelectorTextContains('.sg-topbar .sg-resource--energy', 'Énergie');
        self::assertSelectorTextContains('#buildings-title + .sg-table', 'Mine de métal');
    }

    public function testSwitchesActivePlanet(): void
    {
        $empire = EmpireFactory::createOne();
        $colony = $this->colonyOf($empire);
        $this->client->loginUser($empire->getUser());

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('.sg-sidenav form')->reduce(
            static fn($form): bool => (string) $colony->getId() === $form->filter('input[name="planet"]')->attr('value'),
        )->form());

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Colonie');
        self::assertSame($colony->getId(), $this->reload($empire)->getActivePlanet()->getId());
    }

    public function testCannotSelectAnotherEmpiresPlanet(): void
    {
        $empire = EmpireFactory::createOne();
        $foreign = EmpireFactory::createOne()->getHomePlanet();
        $this->client->loginUser($empire->getUser());
        $crawler = $this->client->request('GET', '/');
        $token = (string) $crawler->filter('.sg-sidenav input[name="_token"]')->attr('value');

        $this->client->request('POST', '/planete-active', ['planet' => $foreign->getId(), '_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($empire->getHomePlanet()->getId(), $this->reload($empire)->getActivePlanet()->getId());
    }

    public function testRefusesSwitchWithoutValidToken(): void
    {
        $empire = EmpireFactory::createOne();
        $colony = $this->colonyOf($empire);
        $this->client->loginUser($empire->getUser());

        $this->client->request('POST', '/planete-active', ['planet' => $colony->getId(), '_token' => 'invalide']);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'La page a expiré');
        self::assertSame($empire->getHomePlanet()->getId(), $this->reload($empire)->getActivePlanet()->getId());
    }

    public function testAccountWithoutEmpireSeesExplanation(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Aucun empire');
    }

    private function colonyOf(Empire $empire): Planet
    {
        $colony = PlanetFactory::createOne();
        $colony->assignTo($empire);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $colony;
    }

    private function reload(Empire $empire): Empire
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = self::getContainer()->get(EmpireRepository::class)->find($empire->getId());
        \assert($reloaded instanceof Empire);

        return $reloaded;
    }
}
