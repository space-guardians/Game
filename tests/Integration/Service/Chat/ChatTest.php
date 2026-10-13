<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Chat;

use App\Exception\Chat\ChatRefused;
use App\Factory\EmpireFactory;
use App\Model\Chat\ChatMessage;
use App\Model\Chat\ChatTopics;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Chat en ligne, canal public (§4.12.1, §5.7) : contrôles du serveur, tampon Redis borné, diffusion sur le hub.
 */
final class ChatTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-13 10:00:00');
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
        parent::tearDown();
    }

    public function testMessageIsBufferedAndBroadcastPrivately(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);

        $message = $this->chat()->post($ana, Chat::PUBLIC_CHANNEL, "  Bonjour\n tout   le monde  ");

        self::assertSame('Bonjour tout le monde', $message->body);
        self::assertSame('Ana', $message->author);
        $recent = $this->chat()->recent($ana, Chat::PUBLIC_CHANNEL);
        self::assertCount(1, $recent);
        self::assertSame($message->id, $recent[0]->id);
        self::assertEquals($message->sentAt, $recent[0]->sentAt);

        $updates = array_values(array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => [ChatTopics::PUBLIC] === $update->getTopics()));
        self::assertCount(1, $updates);
        self::assertTrue($updates[0]->isPrivate());
        self::assertSame(['type' => 'message', 'id' => $message->id, 'channel' => 'public', 'authorId' => $ana->getId(), 'author' => 'Ana', 'body' => 'Bonjour tout le monde', 'sentAt' => '2026-10-13T10:00:00+00:00'], json_decode($updates[0]->getData(), true));
    }

    public function testBufferKeepsOnlyTheLatestMessagesInOrder(): void
    {
        $buffer = self::getContainer()->get(ChatBuffer::class);
        for ($i = 1; $i <= ChatBuffer::SIZE + 5; ++$i) {
            $buffer->push(new ChatMessage('m' . $i, Chat::PUBLIC_CHANNEL, 1, 'Ana', 'Message ' . $i, new \DateTimeImmutable('2026-10-13 10:00:00')));
        }

        $recent = $buffer->recent(Chat::PUBLIC_CHANNEL);

        self::assertCount(ChatBuffer::SIZE, $recent);
        self::assertSame('m6', $recent[0]->id);
        self::assertSame('m' . (ChatBuffer::SIZE + 5), $recent[ChatBuffer::SIZE - 1]->id);
    }

    public function testServerChecksRefuseBadMessages(): void
    {
        $ana = EmpireFactory::createOne();

        foreach ([
            [Chat::PUBLIC_CHANNEL, '   ', 'Le message est vide.'],
            [Chat::PUBLIC_CHANNEL, str_repeat('a', Chat::MAX_LENGTH + 1), 'Un message de chat compte au plus 500 caractères.'],
            ['alliance', 'Salut', 'Canal de discussion inconnu.'],
        ] as [$channel, $body, $error]) {
            try {
                $this->chat()->post($ana, $channel, $body);
                self::fail('Message accepté à tort : ' . $error);
            } catch (ChatRefused $refusal) {
                self::assertSame($error, $refusal->getMessage());
            }
        }
        self::assertSame([], $this->chat()->recent($ana, Chat::PUBLIC_CHANNEL));
    }

    public function testSendingTooFastIsThrottled(): void
    {
        $ana = EmpireFactory::createOne();
        for ($i = 0; $i < 8; ++$i) {
            $this->chat()->post($ana, Chat::PUBLIC_CHANNEL, 'Message ' . $i);
        }

        $this->expectExceptionObject(new ChatRefused('Vous écrivez trop vite : patientez quelques secondes.'));
        $this->chat()->post($ana, Chat::PUBLIC_CHANNEL, 'Encore un');
    }

    private function chat(): Chat
    {
        return self::getContainer()->get(Chat::class);
    }
}
