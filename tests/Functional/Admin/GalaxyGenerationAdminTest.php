<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Entity\GalaxyGeneration;
use App\Enum\Admin\AdminRole;
use App\Enum\Universe\GenerationStatus;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyShapeTemplateFactory;
use App\Message\GenerateGalaxy;
use App\MessageHandler\GenerateGalaxyHandler;
use App\Repository\GalaxyGenerationRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * Génération d'une galaxie depuis le panneau (§5.6.1) : demande, exécution en arrière-plan, suivi.
 */
final class GalaxyGenerationAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testGameDesignerFollowsGenerationsButCannotLaunchOne(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/generations');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/admin/generations/new"]');

        $this->client->request('GET', '/admin/generations/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testModeratorCannotSeeGenerations(): void
    {
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/generations');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRequiresExplicitConfirmation(): void
    {
        $this->loginAs(AdminRole::Admin);

        $this->client->request('GET', '/admin/generations/new');
        $this->client->submitForm('Lancer la génération', ['GalaxyGeneration[systemCount]' => '50']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Confirmez la génération');
        self::assertSame(0, self::getContainer()->get(GalaxyGenerationRepository::class)->count([]));
        self::assertCount(0, $this->transport()->getSent());
    }

    public function testLaunchesGenerationInBackgroundAndFollowsIt(): void
    {
        $admin = $this->loginAs(AdminRole::Admin);
        $template = GalaxyShapeTemplateFactory::createOne(['name' => 'Six bras', 'arms' => 6]);

        $this->client->request('GET', '/admin/generations/new');
        $this->client->submitForm('Lancer la génération', [
            'GalaxyGeneration[name]' => 'Bras d’Orion',
            'GalaxyGeneration[systemCount]' => '50',
            'GalaxyGeneration[shapeTemplate]' => (string) $template->getId(),
            'GalaxyGeneration[confirmation]' => '1',
        ]);

        $generation = self::getContainer()->get(GalaxyGenerationRepository::class)->findOneBy([]);
        self::assertInstanceOf(GalaxyGeneration::class, $generation);
        self::assertResponseRedirects('/admin/generations/' . $generation->getId());
        self::assertSame(GenerationStatus::Pending, $generation->getStatus());
        self::assertNotNull($generation->getSeed(), 'Graine tirée au hasard');
        self::assertSame(6, $generation->getShape()->arms);
        self::assertSame($admin->getId(), $generation->getRequestedBy()?->getId());

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(GenerateGalaxy::class, $message);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-admin-generation', 'En attente');
        self::assertSelectorExists('[data-controller="generation-status"][data-generation-status-finished-value="false"]');

        // Le worker traite le message
        self::getContainer()->get(GenerateGalaxyHandler::class)($message);

        $this->client->request('GET', '/admin/generations/' . $generation->getId() . '/statut');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-admin-generation', 'Terminée');
        self::assertSelectorTextContains('.sg-admin-generation', 'Galaxie 1 — Bras d’Orion');
        self::assertSelectorExists('[data-generation-status-finished-value="true"]');
        self::assertSelectorCount(50, '.sg-admin-preview circle');

        $this->client->request('GET', '/admin/generations');
        self::assertSelectorTextContains('table .badge-success', 'Terminée');
    }

    public function testKeepsChosenSeed(): void
    {
        $this->loginAs(AdminRole::Admin);

        $this->client->request('GET', '/admin/generations/new');
        $this->client->submitForm('Lancer la génération', [
            'GalaxyGeneration[systemCount]' => '50',
            'GalaxyGeneration[seed]' => '42',
            'GalaxyGeneration[confirmation]' => '1',
        ]);

        self::assertSame(42, self::getContainer()->get(GalaxyGenerationRepository::class)->findOneBy([])?->getSeed());
    }

    public function testRejectsOutOfRangeSystemCount(): void
    {
        $this->loginAs(AdminRole::Admin);

        $this->client->request('GET', '/admin/generations/new');
        $this->client->submitForm('Lancer la génération', [
            'GalaxyGeneration[systemCount]' => '5',
            'GalaxyGeneration[confirmation]' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Entre 10 et 5000 systèmes.');
    }

    public function testStatusEndpointRequiresGameDesigner(): void
    {
        $generation = new GalaxyGeneration(null, new \DateTimeImmutable('2026-10-05 10:00:00'));
        $generation->lock(1);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($generation);
        $entityManager->flush();
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/generations/' . $generation->getId() . '/statut');

        self::assertResponseStatusCodeSame(403);
    }

    private function loginAs(AdminRole $role): AdminUser
    {
        $admin = AdminUserFactory::createOne(['role' => $role]);
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
