<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\AdminRole;
use App\Admin\AuditAction;
use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyFactory;
use App\Repository\AdminAuditLogRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Journal d'audit : alimenté par les écritures faites depuis le panneau, consultable par l'administration (§5.6.2).
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

        $this->client->request('GET', '/admin/journal-audit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin');
        self::assertSelectorTextNotContains('nav', 'Journal d’audit');
    }

    public function testEditFromPanelAppearsInJournal(): void
    {
        $galaxy = GalaxyFactory::createOne(['number' => 1, 'name' => 'Orion']);
        $this->loginAs(AdminRole::Admin, 'admin@space-guardians.local');

        $this->client->request('GET', '/admin/galaxies/' . $galaxy->getId() . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', ['Galaxy[name]' => 'Bras d’Orion']);

        $entries = self::getContainer()->get(AdminAuditLogRepository::class)->findBySubject('Galaxy', (string) $galaxy->getId());
        self::assertCount(1, $entries);
        $this->client->request('GET', '/admin/journal-audit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'admin@space-guardians.local');
        self::assertSelectorTextContains('table', 'Modification');
        self::assertSelectorTextContains('table', 'Galaxie 1 — Bras d’Orion');

        $this->client->request('GET', '/admin/journal-audit/' . $entries[0]->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-admin-audit-changes', 'Orion');
        self::assertSelectorTextContains('.sg-admin-audit-changes', 'Bras d’Orion');

        $this->client->request('GET', '/admin/journal-audit', ['filters' => ['action' => ['comparison' => '=', 'value' => AuditAction::Update->value]]]);
        self::assertSelectorTextContains('table', 'Bras d’Orion');
        $this->client->request('GET', '/admin/journal-audit', ['filters' => ['action' => ['comparison' => '=', 'value' => AuditAction::Delete->value]]]);
        self::assertSelectorTextNotContains('body', 'Bras d’Orion');
    }

    public function testJournalIsReadOnly(): void
    {
        $admin = $this->loginAs(AdminRole::SuperAdmin);
        $entry = new AdminAuditLog(new \DateTimeImmutable('2026-10-02 21:00:00'), $admin->getId(), $admin->getEmail(), AuditAction::Sanction, 'User', '1', 'Joueur', []);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($entry);
        $entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/journal-audit');
        self::assertCount(0, $crawler->filter('a[href*="/edit"], a[href$="/new"], form[action*="/delete"]'));

        $this->client->request('GET', '/admin/journal-audit/' . $entry->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/journal-audit/' . $entry->getId() . '/delete');
        self::assertResponseStatusCodeSame(403);
    }

    private function loginAs(AdminRole $role, ?string $email = null): AdminUser
    {
        $admin = AdminUserFactory::createOne(['role' => $role] + (null === $email ? [] : ['email' => $email]));
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }
}
