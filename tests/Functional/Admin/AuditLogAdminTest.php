<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\AdminAudit;
use App\Admin\AdminRole;
use App\Admin\AuditAction;
use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Journal des actions d'administration, consultable par l'administration (§5.6.2).
 */
final class AuditLogAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testGameDesignerCannotReadJournal(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/journal-actions');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin');
        self::assertSelectorTextNotContains('nav', 'Journal des actions');
    }

    public function testRecordedActionAppearsInJournal(): void
    {
        $admin = $this->loginAs(AdminRole::Admin, 'admin@space-guardians.local');
        $galaxy = GalaxyFactory::createOne(['number' => 1, 'name' => 'Orion']);
        $entry = self::getContainer()->get(AdminAudit::class)->record(AuditAction::Generate, $galaxy, ['seed' => [null, 42]], $admin);

        $this->client->request('GET', '/admin/journal-actions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'admin@space-guardians.local');
        self::assertSelectorTextContains('table', 'Génération');
        self::assertSelectorTextContains('table', 'Galaxie 1 — Orion');

        $this->client->request('GET', '/admin/journal-actions/' . $entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-admin-audit-changes', '42');

        $this->client->request('GET', '/admin/journal-actions', ['filters' => ['action' => ['comparison' => '=', 'value' => AuditAction::Generate->value]]]);
        self::assertSelectorTextContains('table', 'Galaxie 1 — Orion');
        $this->client->request('GET', '/admin/journal-actions', ['filters' => ['action' => ['comparison' => '=', 'value' => AuditAction::Sanction->value]]]);
        self::assertSelectorTextNotContains('body', 'Galaxie 1 — Orion');
    }

    public function testJournalIsReadOnly(): void
    {
        $admin = $this->loginAs(AdminRole::SuperAdmin);
        $entry = new AdminAuditLog(new \DateTimeImmutable('2026-10-02 21:00:00'), $admin->getId(), $admin->getEmail(), AuditAction::Sanction, 'User', '1', 'Joueur', []);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($entry);
        $entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/journal-actions');
        self::assertCount(0, $crawler->filter('a[href*="/edit"], a[href$="/new"], form[action*="/delete"]'));

        $this->client->request('GET', '/admin/journal-actions/' . $entry->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/journal-actions/' . $entry->getId() . '/delete');
        self::assertResponseStatusCodeSame(403);
    }

    private function loginAs(AdminRole $role, ?string $email = null): AdminUser
    {
        $admin = AdminUserFactory::createOne(['role' => $role] + (null === $email ? [] : ['email' => $email]));
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }
}
