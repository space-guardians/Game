<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Chat;

use App\Entity\Empire;
use App\Exception\Chat\ChatRecipientOffline;
use App\Exception\Chat\ChatRefused;
use App\Factory\EmpireFactory;
use App\Model\Chat\ChatTopics;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Service\Chat\ChatPresence;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Discussions privées du chat (§4.12.1) : entre deux joueurs connectés, relayées sur leurs topics personnels,
 * lisibles par eux seuls ; un interlocuteur hors ligne renvoie vers la messagerie.
 */
final class PrivateChatTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    /** @var list<string> */
    private array $channels = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-13 10:00:00');
        self::getContainer()->get(ChatPresence::class)->clear();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(ChatPresence::class)->clear();
        foreach ($this->channels as $channel) {
            self::getContainer()->get(ChatBuffer::class)->clear($channel);
        }
        parent::tearDown();
    }

    public function testPrivateMessageReachesBothPlayersOnly(): void
    {
        [$ana, $bob, $eve] = $this->empires('Ana', 'Bob', 'Eve');
        $this->online($bob);

        $chat = $this->chat()->openPrivate($ana, 'bob');
        self::assertSame(Chat::privateChannel($ana, $bob), $chat->channel);
        self::assertSame(Chat::privateChannel($bob, $ana), $chat->channel);
        self::assertSame(['Bob', (int) $bob->getId()], [$chat->peer, $chat->peerId]);
        $this->channels[] = $chat->channel;

        $message = $this->chat()->post($ana, $chat->channel, 'Salut Bob');

        self::assertSame(['Bob', (int) $bob->getId()], [$message->recipient, $message->recipientId]);
        $updates = array_values(array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => str_contains($update->getData(), 'Salut Bob')));
        self::assertCount(1, $updates);
        self::assertTrue($updates[0]->isPrivate());
        self::assertEqualsCanonicalizing([ChatTopics::user($ana->getUser()), ChatTopics::user($bob->getUser())], $updates[0]->getTopics());
        // Fil récent : les deux interlocuteurs seulement
        self::assertCount(1, $this->chat()->recent($bob, $chat->channel));
        $this->expectExceptionObject(new ChatRefused('Canal de discussion inconnu.'));
        $this->chat()->recent($eve, $chat->channel);
    }

    public function testOfflinePlayerCannotBeReachedByChat(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        try {
            $this->chat()->openPrivate($ana, 'Bob');
            self::fail('Discussion ouverte avec un joueur hors ligne.');
        } catch (ChatRecipientOffline $offline) {
            self::assertSame($bob, $offline->recipient);
            self::assertSame('Bob est hors ligne : écrivez-lui plutôt dans la messagerie.', $offline->getMessage());
        }

        // Connecté à l'ouverture, puis parti : l'envoi est refusé aussi
        $this->online($bob);
        $channel = $this->chat()->openPrivate($ana, 'Bob')->channel;
        self::getContainer()->get(ChatPresence::class)->leave($bob);
        $this->expectException(ChatRecipientOffline::class);
        $this->chat()->post($ana, $channel, 'Tu es là ?');
    }

    public function testOnlyParticipantsWriteInAPrivateChannel(): void
    {
        [$ana, $bob, $eve] = $this->empires('Ana', 'Bob', 'Eve');
        $this->online($ana);
        $this->online($bob);

        foreach ([
            [fn() => $this->chat()->post($eve, Chat::privateChannel($ana, $bob), 'Coucou'), 'Canal de discussion inconnu.'],
            [fn() => $this->chat()->post($ana, 'private:9-3', 'Coucou'), 'Canal de discussion inconnu.'],
            [fn() => $this->chat()->openPrivate($ana, 'Ana'), 'On ne discute pas avec soi-même.'],
            [fn() => $this->chat()->openPrivate($ana, 'Personne'), 'Aucun empire nommé « Personne ».'],
        ] as [$attempt, $error]) {
            try {
                $attempt();
                self::fail('Accepté à tort : ' . $error);
            } catch (ChatRefused $refusal) {
                self::assertSame($error, $refusal->getMessage());
            }
        }
    }

    private function online(Empire $empire): void
    {
        self::getContainer()->get(ChatPresence::class)->heartbeat($empire);
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(static fn(string $name): Empire => EmpireFactory::createOne(['name' => $name]), array_values($names));
    }

    private function chat(): Chat
    {
        return self::getContainer()->get(Chat::class);
    }
}
