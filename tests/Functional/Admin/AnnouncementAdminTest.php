<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Conversation;
use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Enum\Messaging\SystemAudience;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Message\DeliverSystemMessage;
use App\Repository\AdminAuditLogRepository;
use App\Repository\ConversationRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * Annonces dans le panneau (§4.12.2, §5.6.1) : message du jeu à des joueurs, une alliance ou tous les joueurs.
 */
final class AnnouncementAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testModeratorAnnouncesToPlayersByName(): void
    {
        EmpireFactory::createOne(['name' => 'Ana']);
        EmpireFactory::createOne(['name' => 'Bob']);
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin/annonces');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Envoyer l’annonce', [
            'audience' => SystemAudience::Empires->value,
            'recipients' => 'Ana, Bob',
            'subject' => 'Maintenance',
            'body' => 'Le serveur redémarre à minuit.',
        ]);

        self::assertResponseRedirects('/admin/annonces');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Annonce envoyée à Ana, Bob.');
        $conversation = self::getContainer()->get(ConversationRepository::class)->findOneBy(['system' => true]);
        self::assertInstanceOf(Conversation::class, $conversation);
        self::assertCount(2, $conversation->getParticipants());
        $entries = self::getContainer()->get(AdminAuditLogRepository::class)->findBySubject('Conversation', (string) $conversation->getId());
        self::assertSame(AuditAction::Announce, $entries[0]->getAction());
    }

    public function testAnnouncementToEveryoneIsQueued(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin/annonces');
        $this->client->submitForm('Envoyer l’annonce', ['audience' => SystemAudience::All->value, 'body' => 'Nouvelle saison !']);

        self::assertResponseRedirects('/admin/annonces');
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $queued = array_filter($transport->getSent(), static fn($envelope): bool => $envelope->getMessage() instanceof DeliverSystemMessage);
        self::assertCount(1, $queued);
    }

    public function testUnknownAllianceIsRefused(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin/annonces');
        $this->client->submitForm('Envoyer l’annonce', ['audience' => SystemAudience::Alliance->value, 'alliance' => 'ZZZ', 'body' => 'Bonjour']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Aucune alliance au tag « ZZZ ».');
    }

    public function testSendingRequiresTheSameOrigin(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://ailleurs.example');

        $this->client->request('POST', '/admin/annonces/envoyer', ['audience' => 'all', 'body' => 'Bonjour']);

        self::assertResponseStatusCodeSame(403);
    }
}
