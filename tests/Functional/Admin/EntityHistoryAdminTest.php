<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\AdminRole;
use App\Admin\AuditAction;
use App\Entity\AdminUser;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyFactory;
use App\Repository\AdminAuditLogRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Consultation de l'historique des modifications dans le panneau (§5.6.3).
 */
final class EntityHistoryAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testGameDesignerCannotReadHistory(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        foreach (['/admin/historique', '/admin/historique/galaxy', '/admin/historique/galaxy/1'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('GET', '/admin');
        self::assertSelectorTextNotContains('nav', 'Historique des données');
    }

    public function testEditFromPanelIsTracedWithAdminAuthor(): void
    {
        $galaxy = GalaxyFactory::createOne(['number' => 1, 'name' => 'Orion']);
        $this->loginAs(AdminRole::Admin, 'admin@space-guardians.local');

        $this->client->request('GET', '/admin/galaxies/' . $galaxy->getId() . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', ['Galaxy[name]' => 'Bras d’Orion']);

        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('nav', 'Historique des données');
        $crawler = $this->client->request('GET', '/admin/historique');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Galaxies');

        $crawler = $this->client->request('GET', '/admin/historique/galaxy', ['type' => 'update']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Modification');
        self::assertSelectorTextContains('table', 'admin@space-guardians.local Administration');

        $this->client->click($crawler->selectLink('Détail')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Modification — Galaxies #' . $galaxy->getId());
        self::assertSelectorTextContains('.sg-admin-audit-changes', 'Orion');
        self::assertSelectorTextContains('.sg-admin-audit-changes', 'Bras d’Orion');
    }

    public function testFiltersHistory(): void
    {
        $galaxy = GalaxyFactory::createOne(['number' => 1, 'name' => 'Orion']);
        $this->loginAs(AdminRole::Admin);
        $this->client->request('GET', '/admin/galaxies/' . $galaxy->getId() . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', ['Galaxy[name]' => 'Bras d’Orion']);

        $this->client->request('GET', '/admin/historique/galaxy', ['origine' => 'main']);
        self::assertSelectorTextContains('table', 'Aucune modification ne correspond.');
        $this->client->request('GET', '/admin/historique/galaxy', ['origine' => 'admin', 'objet' => (string) $galaxy->getId()]);
        self::assertSelectorTextContains('p.text-muted', '1 entrée');
        $this->client->request('GET', '/admin/historique/galaxy', ['type' => 'remove']);
        self::assertSelectorTextContains('table', 'Aucune modification ne correspond.');
    }

    public function testDetailPageLinksToObjectHistory(): void
    {
        $galaxy = GalaxyFactory::createOne();
        $this->loginAs(AdminRole::Admin);

        $crawler = $this->client->request('GET', '/admin/galaxies/' . $galaxy->getId());

        self::assertStringEndsWith(
            '/admin/historique/galaxy?objet=' . $galaxy->getId(),
            (string) $crawler->filter('[data-action-name="history"]')->link()->getUri(),
        );
    }

    public function testDeletionShowsDeletedObject(): void
    {
        GalaxyFactory::createOne(['number' => 9, 'name' => 'Éphémère']);
        $this->loginAs(AdminRole::Admin);

        $crawler = $this->client->request('GET', '/admin/galaxies');
        $deleteUrl = (string) $crawler->filter('[data-action-name="delete"]')->first()->attr('href');
        $this->client->request('POST', $deleteUrl, ['token' => (string) $crawler->filter('input[name="token"]')->first()->attr('value')]);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/admin/historique/galaxy', ['type' => 'remove']);
        $this->client->click($crawler->selectLink('Détail')->link());
        self::assertSelectorTextContains('body', 'Objet supprimé : Galaxie 9 — Éphémère');
    }

    public function testUnknownEntityIsNotFound(): void
    {
        $this->loginAs(AdminRole::Admin);

        $this->client->request('GET', '/admin/historique/planet');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTwoFactorResetIsJournaled(): void
    {
        $this->loginAs(AdminRole::SuperAdmin);
        $other = AdminUserFactory::createOne(['email' => 'perdu@space-guardians.local']);

        $crawler = $this->client->request('GET', '/admin/comptes/' . $other->getId());
        $this->client->submit($crawler->selectButton('Réinitialiser la double authentification')->form());

        $entries = self::getContainer()->get(AdminAuditLogRepository::class)->findBySubject('AdminUser', (string) $other->getId());
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::ResetTwoFactor, $entries[0]->getAction());
    }

    private function loginAs(AdminRole $role, ?string $email = null): AdminUser
    {
        $admin = AdminUserFactory::createOne(['role' => $role] + (null === $email ? [] : ['email' => $email]));
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }
}
