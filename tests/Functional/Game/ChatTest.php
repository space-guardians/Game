<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Factory\EmpireFactory;
use App\Model\Chat\ChatTopics;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Service\Chat\ChatPresence;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Chat en ligne (§4.12.1, §5.7) : widget permanent sur les pages du jeu, envoi par POST contrôlé par le serveur, fil
 * récent du canal public.
 */
final class ChatTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
        self::getContainer()->get(ChatPresence::class)->clear();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
        self::getContainer()->get(ChatPresence::class)->clear();
        parent::tearDown();
    }

    public function testWidgetSubscribesToChatTopicsAndSurvivesNavigation(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $this->client->loginUser($ana->getUser());

        $crawler = $this->client->request('GET', '/');

        $widget = $crawler->filter('#sg-chat');
        self::assertCount(1, $widget);
        self::assertNotNull($widget->attr('data-turbo-permanent'));
        self::assertSame('chat', $widget->attr('data-controller'));
        $hub = (string) $widget->attr('data-chat-hub-value');
        self::assertStringContainsString(rawurlencode(ChatTopics::PUBLIC), $hub);
        self::assertStringContainsString(rawurlencode('/users/' . $ana->getUser()->getId() . '/chat'), $hub);
        self::assertSame((string) $ana->getId(), $widget->attr('data-chat-me-value'));
        self::assertSelectorTextContains('#sg-chat [role="tab"]', 'Public');

        // Le cookie d'abonnement couvre les topics du chat et ceux du jeu
        $cookie = $this->client->getCookieJar()->get('mercure_access_token', '/.well-known/mercure');
        self::assertNotNull($cookie);
        $claims = json_decode(base64_decode(strtr(explode('.', $cookie->getValue())[1], '-_', '+/')), true);
        $subscribe = array_column($claims['authorization_details'][0]['topics'] ?? [], 'match');
        self::assertContains(ChatTopics::PUBLIC, $subscribe);
        self::assertContains('/users/' . $ana->getUser()->getId() . '/chat', $subscribe);
        self::assertContains('/empire/' . $ana->getId(), $subscribe);
    }

    public function testMessageIsSentThroughTheServerAndReadBack(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $this->client->loginUser($ana->getUser());
        $crawler = $this->client->request('GET', '/');
        $token = (string) $crawler->filter('#sg-chat')->attr('data-chat-token-value');

        $this->post(['channel' => 'public', 'body' => 'Salut la galaxie'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Salut la galaxie', json_decode((string) $this->client->getResponse()->getContent(), true)['body'] ?? null);

        $this->post(['channel' => 'public', 'body' => ''], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Le message est vide.', json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null);

        $this->client->request('GET', '/chat/messages?canal=public');
        self::assertResponseIsSuccessful();
        $history = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['Ana', 'Salut la galaxie'], [$history[0]['author'] ?? null, $history[0]['body'] ?? null]);
    }

    public function testSendingRequiresTheCsrfTokenAndAPlayer(): void
    {
        $this->post(['channel' => 'public', 'body' => 'Anonyme'], 'jeton');
        self::assertResponseRedirects();

        $this->client->loginUser(EmpireFactory::createOne()->getUser());
        $this->post(['channel' => 'public', 'body' => 'Sans jeton'], 'faux');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], self::getContainer()->get(ChatBuffer::class)->recent(Chat::PUBLIC_CHANNEL));
    }

    public function testHeartbeatShowsWhoIsOnlineAndLogoutGoesOffline(): void
    {
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        self::getContainer()->get(ChatPresence::class)->heartbeat($bob);
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $this->client->loginUser($ana->getUser());
        $crawler = $this->client->request('GET', '/');
        $token = (string) $crawler->filter('#sg-chat')->attr('data-chat-token-value');
        self::assertSame('/chat/presence', $crawler->filter('#sg-chat')->attr('data-chat-presence-url-value'));

        $this->client->request('POST', '/chat/presence', server: ['HTTP_X_CSRF_TOKEN' => $token]);

        self::assertResponseIsSuccessful();
        $presence = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(2, $presence['count'] ?? null);
        self::assertSame(['Ana', 'Bob'], array_column($presence['players'] ?? [], 'name'));
        self::assertTrue(self::getContainer()->get(ChatPresence::class)->isOnline((int) $ana->getId()));

        $this->client->request('GET', '/deconnexion');
        self::assertFalse(self::getContainer()->get(ChatPresence::class)->isOnline((int) $ana->getId()));
    }

    public function testHeartbeatRequiresTheCsrfToken(): void
    {
        $ana = EmpireFactory::createOne();
        $this->client->loginUser($ana->getUser());

        $this->client->request('POST', '/chat/presence', server: ['HTTP_X_CSRF_TOKEN' => 'faux']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse(self::getContainer()->get(ChatPresence::class)->isOnline((int) $ana->getId()));
    }

    /** @param array<string, string> $payload */
    private function post(array $payload, string $token): void
    {
        $this->client->request('POST', '/chat/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
