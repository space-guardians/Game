<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Repository\GalaxyRepository;
use App\Repository\PlanetRepository;
use App\Repository\StarSystemRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class UniverseAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private Galaxy $galaxy;
    private StarSystem $system;
    private Planet $planet;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');

        $this->galaxy = GalaxyFactory::createOne(['number' => 1, 'name' => 'Voie des Gardiens']);
        $this->system = StarSystemFactory::createOne(['galaxy' => $this->galaxy, 'number' => 342, 'position' => new GlobalPosition(300.0, 400.0)]);
        $this->planet = PlanetFactory::createOne(['system' => $this->system, 'position' => new OrbitalPosition(7, 44.0, M_PI), 'temperature' => -12]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function universePages(): iterable
    {
        yield 'galaxies' => ['/admin/galaxies'];
        yield 'systèmes' => ['/admin/systemes'];
        yield 'planètes' => ['/admin/planetes'];
    }

    #[DataProvider('universePages')]
    public function testModeratorCannotSeeUniverse(string $page): void
    {
        $this->loginAs('ROLE_MODERATOR');

        $this->client->request('GET', $page);

        self::assertResponseStatusCodeSame(403);
    }

    public function testModeratorMenuHasNoUniverseSection(): void
    {
        $this->loginAs('ROLE_MODERATOR');

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/galaxies"]');
    }

    public function testGameDesignerBrowsesGalaxiesSystemsAndPlanets(): void
    {
        $this->loginAs('ROLE_GAME_DESIGNER');

        $this->client->request('GET', '/admin/galaxies');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Voie des Gardiens');

        $this->client->request('GET', '/admin/systemes/' . $this->system->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Système 342 (galaxie 1)');
        self::assertSelectorTextContains('body', '500');      // distance au centre
        self::assertSelectorTextContains('body', '[1:342:7]'); // planètes du système

        $this->client->request('GET', '/admin/planetes/' . $this->planet->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Planète [1:342:7]');
        self::assertSelectorTextContains('body', '180°');
    }

    public function testGameDesignerCannotRenameGalaxy(): void
    {
        $this->loginAs('ROLE_GAME_DESIGNER');

        $this->client->request('GET', '/admin/galaxies/' . $this->galaxy->getId() . '/edit');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenActions(): iterable
    {
        yield 'créer une galaxie' => ['/admin/galaxies/new'];
        yield 'créer un système' => ['/admin/systemes/new'];
        yield 'créer une planète' => ['/admin/planetes/new'];
    }

    /** Galaxies, systèmes et planètes viennent de la génération, jamais d'un formulaire libre */
    #[DataProvider('forbiddenActions')]
    public function testNobodyCreatesUniverseByHand(string $page): void
    {
        $this->loginAs('ROLE_SUPER_ADMIN');

        $this->client->request('GET', $page);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminRenamesGalaxy(): void
    {
        $this->loginAs('ROLE_ADMIN');

        $this->client->request('GET', '/admin/galaxies/' . $this->galaxy->getId() . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', ['Galaxy[name]' => 'Bras d’Orion']);

        self::assertResponseRedirects();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame('Bras d’Orion', self::getContainer()->get(GalaxyRepository::class)->find($this->galaxy->getId())?->getName());
    }

    public function testAdminDeletesGalaxyWithItsSystemsAndPlanets(): void
    {
        $this->loginAs('ROLE_ADMIN');
        $crawler = $this->client->request('GET', '/admin/galaxies');
        $deleteUrl = (string) $crawler->filter('[data-action-name="delete"]')->first()->attr('href');
        // Jeton du formulaire de confirmation de suppression
        $token = (string) $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', $deleteUrl, ['token' => $token]);

        self::assertResponseRedirects();
        self::assertSame(0, self::getContainer()->get(GalaxyRepository::class)->count([]));
        self::assertSame(0, self::getContainer()->get(StarSystemRepository::class)->count([]));
        self::assertSame(0, self::getContainer()->get(PlanetRepository::class)->count([]));
    }

    private function loginAs(string $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
