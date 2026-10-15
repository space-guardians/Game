<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\MessageReport;
use App\Enum\Admin\AdminRole;
use App\Enum\Moderation\ReportStatus;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Repository\MessageReportRepository;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Service\Messaging\Messaging;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Signalements (§4.13) : depuis une conversation et depuis le chat, puis traités dans le panneau (rôle Moderator).
 */
final class MessageReportAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
        parent::tearDown();
    }

    public function testAPlayerReportsAMessageAndAModeratorHandlesIt(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $conversation = self::getContainer()->get(Messaging::class)->start($bob, ['Ana'], null, 'Message déplacé');
        $this->client->loginUser($ana->getUser());

        $this->client->request('GET', '/messagerie/' . $conversation->getId());
        $this->client->submitForm('Signaler à la modération', ['reason' => 'Propos déplacés']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Signalement transmis à la modération.');
        // Son propre message ne se signale pas
        self::assertSelectorCount(1, '.sg-message__report');

        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');
        $crawler = $this->client->request('GET', '/admin/signalements');
        self::assertSelectorTextContains('table', 'Propos déplacés');
        $this->client->click($crawler->filter('a.action-detail')->link());
        self::assertSelectorTextContains('body', 'Message déplacé');
        self::assertSelectorTextContains('body', 'Fiche du joueur signalé');
        $this->client->submitForm('Classer sans suite');
        $this->client->followRedirect();

        $report = self::getContainer()->get(MessageReportRepository::class)->findOneBy([]);
        self::assertInstanceOf(MessageReport::class, $report);
        self::assertSame(ReportStatus::Dismissed, $report->getStatus());
    }

    public function testAChatMessageIsReportedFromTheWidget(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $message = self::getContainer()->get(Chat::class)->post($bob, Chat::PUBLIC_CHANNEL, 'Spam spam spam');
        $this->client->loginUser($ana->getUser());
        $crawler = $this->client->request('GET', '/flotte');
        $token = (string) $crawler->filter('#sg-chat')->attr('data-chat-token-value');

        $this->client->request('POST', '/chat/signalements', server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['channel' => Chat::PUBLIC_CHANNEL, 'id' => $message->id, 'reason' => 'Spam'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Spam spam spam', self::getContainer()->get(MessageReportRepository::class)->findOneBy([])?->getBody());
    }
}
