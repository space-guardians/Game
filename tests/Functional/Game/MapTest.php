<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Carte (§2.3, §5.5) : page portant le contrôleur Stimulus, endpoint JSON de la zone visible.
 */
final class MapTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testPageCentersOnActivePlanetSystem(): void
    {
        $empire = $this->login();
        $system = $empire->getHomePlanet()->getSystem();

        $crawler = $this->client->request('GET', '/carte');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Carte');
        $map = $crawler->filter('[data-controller="galaxy-map"]');
        self::assertSame(\sprintf('/carte/%d/donnees', $system->getGalaxy()->getNumber()), $map->attr('data-galaxy-map-url-value'));
        self::assertEqualsWithDelta($system->getPosition()->x, (float) $map->attr('data-galaxy-map-center-x-value'), 1e-6);
        self::assertSelectorExists('svg[data-galaxy-map-target="svg"]');
    }

    public function testGalaxyViewAggregatesPlanetsPerVisibleSystem(): void
    {
        $empire = $this->login();
        $home = $empire->getHomePlanet()->getSystem();
        $neighbour = $this->system($empire, 500, 0, ['free', 'occupied', 'mine']);
        $this->system($empire, 50_000, 0, ['free']);

        $data = $this->data($empire, ['x1' => $home->getPosition()->x - 100, 'y1' => $home->getPosition()->y - 100, 'x2' => $home->getPosition()->x + 600, 'y2' => $home->getPosition()->y + 100]);

        self::assertFalse($data['detailed']);
        self::assertSame([], $data['planets']);
        $systems = array_column($data['systems'], null, 'id');
        // Le système lointain est hors de la zone visible
        self::assertEqualsCanonicalizing([$home->getId(), $neighbour], array_keys($systems));
        self::assertSame(['planets' => 3, 'free' => 1, 'occupied' => 2, 'mine' => 1], array_intersect_key($systems[$neighbour], array_flip(['planets', 'free', 'occupied', 'mine'])));
        self::assertSame(1, $systems[$home->getId()]['mine']);
    }

    public function testSystemViewListsPlanetsAtTheirPosition(): void
    {
        $empire = $this->login();
        $home = $empire->getHomePlanet();
        $position = $home->getSystem()->getPosition();

        $data = $this->data($empire, ['x1' => $position->x - 100, 'y1' => $position->y - 100, 'x2' => $position->x + 100, 'y2' => $position->y + 100, 'detail' => '1']);

        self::assertTrue($data['detailed']);
        $planet = array_column($data['planets'], null, 'id')[$home->getId()];
        self::assertSame((string) $home->getAddress(), $planet['address']);
        self::assertTrue($planet['mine']);
        self::assertSame($empire->getName(), $planet['empire']);
        ['x' => $x, 'y' => $y] = $home->getPosition()->toLocalCartesian();
        self::assertEqualsWithDelta($position->x + $x, $planet['x'], 1e-6);
        self::assertEqualsWithDelta($position->y + $y, $planet['y'], 1e-6);
    }

    public function testMapRequiresAPlayer(): void
    {
        $this->client->request('GET', '/carte/1/donnees');

        self::assertResponseRedirects('http://localhost/connexion');
    }

    private function login(): Empire
    {
        $empire = EmpireFactory::createOne();
        $this->client->loginUser($empire->getUser());

        return $empire;
    }

    /**
     * Système de la galaxie de l'empire, décalé de (dx ; dy) par rapport à son système, avec des planètes libres,
     * occupées par un autre empire ou par l'empire.
     *
     * @param list<'free'|'occupied'|'mine'> $planets
     */
    private function system(Empire $empire, float $dx, float $dy, array $planets): int
    {
        $home = $empire->getHomePlanet()->getSystem();
        $system = StarSystemFactory::createOne([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + $dx, $home->getPosition()->y + $dy),
        ]);
        $other = null;
        foreach ($planets as $orbit => $kind) {
            $planet = PlanetFactory::createOne(['system' => $system, 'position' => new OrbitalPosition($orbit + 1, ($orbit + 1) * 10.0, 0.0)]);
            if ('mine' === $kind) {
                $planet->assignTo($empire);
            } elseif ('occupied' === $kind) {
                $other ??= EmpireFactory::createOne();
                $planet->assignTo($other);
            }
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return (int) $system->getId();
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array{detailed: bool, systems: list<array<string, mixed>>, planets: list<array<string, mixed>>}
     */
    private function data(Empire $empire, array $query): array
    {
        $this->client->request('GET', \sprintf('/carte/%d/donnees', $empire->getHomePlanet()->getSystem()->getGalaxy()->getNumber()), $query);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
