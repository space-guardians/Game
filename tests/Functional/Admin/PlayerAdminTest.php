<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Empire;
use App\Entity\EmpireSnapshot;
use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Factory\UserFactory;
use App\Model\Ranking\ScoreBreakdown;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Joueurs dans le panneau (§5.6.1) : liste, recherche, fiche empire et journal d'activité, dès le rôle Moderator.
 */
final class PlayerAdminTest extends WebTestCase
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

    public function testModeratorListsAndSearchesPlayers(): void
    {
        EmpireFactory::createOne(['name' => 'Ordre d’Orion', 'user' => UserFactory::new(['email' => 'orion@exemple.fr'])]);
        EmpireFactory::createOne(['name' => 'Ligue de Véga', 'user' => UserFactory::new(['email' => 'vega@exemple.fr'])]);
        $this->loginAdmin(AdminRole::Moderator);

        $this->client->request('GET', '/admin/joueurs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Ordre d’Orion');
        self::assertSelectorTextContains('table', 'Ligue de Véga');
        self::assertSelectorTextContains('table', 'Jamais');

        $this->client->request('GET', '/admin/joueurs', ['query' => 'vega@']);
        self::assertSelectorTextContains('table', 'Ligue de Véga');
        self::assertSelectorTextNotContains('table', 'Ordre d’Orion');

        $this->client->request('GET', '/admin/joueurs', ['query' => 'Orion']);
        self::assertSelectorTextContains('table', 'Ordre d’Orion');
        self::assertSelectorTextNotContains('table', 'Ligue de Véga');
    }

    public function testPlayerFileShowsPlanetsAndActivity(): void
    {
        $empire = EmpireFactory::createOne(['name' => 'Ordre d’Orion', 'foundedAt' => new \DateTimeImmutable('2026-10-06 10:00:00')]);
        $this->construct($empire);
        $this->loginAdmin(AdminRole::Moderator);

        $this->client->request('GET', \sprintf('/admin/joueurs/%d', $empire->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ordre d’Orion');
        $planet = \sprintf('#planete-%d', $empire->getHomePlanet()->getId());
        self::assertSelectorTextContains($planet, 'Planète mère');
        self::assertSelectorTextContains($planet, 'Métal');
        self::assertSelectorTextContains($planet, 'Mine de métal niveau 1, fin le 06/10/2026');
        self::assertSelectorTextContains('#actions-joueur', 'Constructions en cours');
        self::assertSelectorTextContains('#actions-joueur', 'Création');
        self::assertSelectorTextContains('#evenements-joueur', 'Fin de construction : metal_mine niveau 1');
        self::assertSelectorTextContains('#evenements-joueur', 'En attente');
        // L'historique détaillé est réservé au rôle Admin (§5.6.3)
        self::assertSelectorNotExists('#actions-joueur a');
    }

    public function testAdminFollowsActivityToHistory(): void
    {
        $empire = EmpireFactory::createOne();
        $this->construct($empire);
        $this->loginAdmin(AdminRole::Admin);

        $crawler = $this->client->request('GET', \sprintf('/admin/joueurs/%d', $empire->getId()));
        $this->client->click($crawler->filter('#actions-joueur a')->first()->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $empire->getUser()->getEmail());
    }

    public function testPlayerAccountCannotOpenAdminPlayers(): void
    {
        $empire = EmpireFactory::createOne();
        $this->client->loginUser($empire->getUser());

        $this->client->request('GET', '/admin/joueurs');

        self::assertResponseRedirects('http://localhost/admin/connexion');
    }

    /** Le joueur lance une construction depuis le jeu : action enregistrée à son nom */
    private function construct(Empire $empire): void
    {
        $this->client->loginUser($empire->getUser());
        $crawler = $this->client->request('GET', '/batiments');
        $this->client->submit($crawler->filter('.sg-entity')->first()->selectButton('Construire niveau 1')->form());
        self::assertResponseRedirects('/batiments');
    }

    public function testPlayerFileChartsTheProgressAgainstAverageAndTopTen(): void
    {
        $orion = EmpireFactory::createOne(['name' => 'Ordre d’Orion']);
        $vega = EmpireFactory::createOne(['name' => 'Ligue de Véga']);
        $this->loginAdmin(AdminRole::Moderator);

        $this->client->request('GET', \sprintf('/admin/joueurs/%d', $orion->getId()));
        self::assertSelectorTextContains('#progression', 'Aucun instantané sur les 30 derniers jours');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['-12 hours' => [40, 100], '-6 hours' => [60, 120]] as $offset => [$orionTotal, $vegaTotal]) {
            $at = new \DateTimeImmutable('2026-10-06 10:00:00')->modify($offset);
            foreach ([[$orion, $orionTotal], [$vega, $vegaTotal]] as [$empire, $total]) {
                $entityManager->persist(new EmpireSnapshot($empire, $at, new ScoreBreakdown($total, 0, 0, 0, $total), 10 * $total, 3, 1));
            }
        }
        $entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/admin/joueurs/%d', $orion->getId()));

        self::assertResponseIsSuccessful();
        $charts = $crawler->filter('#progression canvas[data-controller="symfony--ux-chartjs--chart"]');
        self::assertCount(5, $charts);
        self::assertSame(['Score global', 'Économie : production horaire', 'Flotte : vaisseaux', 'Planètes', 'Score par catégorie'], $crawler->filter('#progression figcaption')->each(static fn($caption): string => $caption->text()));
        $score = json_decode((string) $charts->first()->attr('data-symfony--ux-chartjs--chart-view-value'), true);
        self::assertSame(['05/10 22h', '06/10 04h'], $score['data']['labels']);
        self::assertSame(['Ordre d’Orion', 'Moyenne des empires', 'Moyenne du top 10'], array_column($score['data']['datasets'], 'label'));
        self::assertSame([40, 60], $score['data']['datasets'][0]['data']);
        self::assertEquals([70, 90], $score['data']['datasets'][1]['data']);
    }

    private function loginAdmin(AdminRole $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
