<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Admin;

use App\Message\ResolveScheduledEvent;
use App\Service\Admin\MessengerSupervision;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Supervision des files Messenger (§5.6.1) : messages en échec listés, relancés dans leur file d'origine ou supprimés.
 */
final class MessengerSupervisionTest extends KernelTestCase
{
    public function testListsFailedMessagesWithTheirError(): void
    {
        $id = $this->sendToFailureQueue(new ResolveScheduledEvent(42), new \RuntimeException('Base indisponible'));

        $messages = $this->supervision()->list(MessengerSupervision::FAILED_TRANSPORT);

        self::assertSame(1, $this->supervision()->count(MessengerSupervision::FAILED_TRANSPORT));
        self::assertCount(1, $messages);
        self::assertSame($id, $messages[0]->id);
        self::assertSame('ResolveScheduledEvent', $messages[0]->shortClass());
        self::assertSame('Base indisponible', $messages[0]->error);
        self::assertSame(\RuntimeException::class, $messages[0]->errorClass);
        self::assertSame('async', $messages[0]->originalTransport);
        self::assertEquals(new \DateTimeImmutable('2026-10-06 12:00:00'), $messages[0]->failedAt);
        self::assertSame(\sprintf('Message ResolveScheduledEvent #%s (file failed)', $id), (string) $messages[0]);
    }

    public function testRetrySendsMessageBackToItsOriginalQueue(): void
    {
        $id = $this->sendToFailureQueue(new ResolveScheduledEvent(42), new \RuntimeException('Base indisponible'));

        $this->supervision()->retry($id);

        self::assertNull($this->supervision()->findFailed($id));
        $sent = $this->async()->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new ResolveScheduledEvent(42), $sent[0]->getMessage());
        // Renvoyé comme un message neuf : il retrouve ses tentatives automatiques
        self::assertNull($sent[0]->last(RedeliveryStamp::class));
        self::assertNull($sent[0]->last(SentToFailureTransportStamp::class));
        self::assertNull($sent[0]->last(ErrorDetailsStamp::class));
    }

    public function testRemoveDiscardsMessage(): void
    {
        $id = $this->sendToFailureQueue(new ResolveScheduledEvent(42), new \RuntimeException('Base indisponible'));

        $removed = $this->supervision()->remove($id);

        self::assertSame($id, $removed->id);
        self::assertSame(0, $this->supervision()->count(MessengerSupervision::FAILED_TRANSPORT));
        self::assertSame([], $this->async()->getSent());
    }

    public function testUnknownMessageCannotBeRetried(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->supervision()->retry('999999');
    }

    /** Message envoyé dans la file d'échec comme le fait Messenger après ses tentatives automatiques */
    private function sendToFailureQueue(object $message, \Throwable $error): string
    {
        $failed = self::getContainer()->get('messenger.transport.failed');
        $failed->send(new Envelope($message, [
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(3, new \DateTimeImmutable('2026-10-06 12:00:00')),
            ErrorDetailsStamp::create($error),
        ]));
        $messages = $this->supervision()->list(MessengerSupervision::FAILED_TRANSPORT);

        return $messages[array_key_last($messages)]->id;
    }

    private function supervision(): MessengerSupervision
    {
        return self::getContainer()->get(MessengerSupervision::class);
    }

    private function async(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
