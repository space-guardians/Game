<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Model\Admin\QueuedMessage;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Supervision des files Messenger depuis le panneau (§5.6.1) : messages en attente de traitement dans la file
 * « async », messages en échec dans la file « failed », que l'on relance (renvoi dans leur file d'origine) ou
 * supprime. Équivalent des commandes messenger:failed:retry / remove, sans accès au serveur.
 */
final readonly class MessengerSupervision
{
    public const string PENDING_TRANSPORT = 'async';
    public const string FAILED_TRANSPORT = 'failed';
    public const int LIST_LIMIT = 50;

    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $transports,
    ) {}

    /** Nombre de messages, ou null si le transport ne sait pas les compter */
    public function count(string $transport): ?int
    {
        $receiver = $this->transport($transport);

        return $receiver instanceof MessageCountAwareInterface ? $receiver->getMessageCount() : null;
    }

    /**
     * Premiers messages d'une file (vide si le transport ne sait pas les lister).
     *
     * @return list<QueuedMessage>
     */
    public function list(string $transport, int $limit = self::LIST_LIMIT): array
    {
        $receiver = $this->transport($transport);
        if (!$receiver instanceof ListableReceiverInterface) {
            return [];
        }

        $messages = [];
        foreach ($receiver->all($limit) as $envelope) {
            $messages[] = $this->describe($envelope, $transport);
        }

        return $messages;
    }

    public function findFailed(string $id): ?QueuedMessage
    {
        $envelope = $this->failedEnvelope($id);

        return null === $envelope ? null : $this->describe($envelope, self::FAILED_TRANSPORT);
    }

    /**
     * Renvoie un message en échec dans sa file d'origine, où le worker le traitera de nouveau.
     *
     * @throws \InvalidArgumentException message introuvable
     */
    public function retry(string $id): QueuedMessage
    {
        $envelope = $this->failedEnvelope($id) ?? throw new \InvalidArgumentException(\sprintf('Message en échec #%s introuvable.', $id));
        $message = $this->describe($envelope, self::FAILED_TRANSPORT);

        $this->transport($message->originalTransport ?? self::PENDING_TRANSPORT)->send($envelope->withoutStampsOfType(SentToFailureTransportStamp::class)
            ->withoutStampsOfType(RedeliveryStamp::class)
            ->withoutStampsOfType(DelayStamp::class)
            ->withoutStampsOfType(ErrorDetailsStamp::class)
            ->withoutStampsOfType(TransportMessageIdStamp::class));
        $this->transport(self::FAILED_TRANSPORT)->reject($envelope);

        return $message;
    }

    /**
     * Supprime définitivement un message en échec.
     *
     * @throws \InvalidArgumentException message introuvable
     */
    public function remove(string $id): QueuedMessage
    {
        $envelope = $this->failedEnvelope($id) ?? throw new \InvalidArgumentException(\sprintf('Message en échec #%s introuvable.', $id));
        $this->transport(self::FAILED_TRANSPORT)->reject($envelope);

        return $this->describe($envelope, self::FAILED_TRANSPORT);
    }

    private function failedEnvelope(string $id): ?Envelope
    {
        $receiver = $this->transport(self::FAILED_TRANSPORT);
        \assert($receiver instanceof ListableReceiverInterface);

        return $receiver->find($id);
    }

    private function describe(Envelope $envelope, string $transport): QueuedMessage
    {
        $error = $envelope->last(ErrorDetailsStamp::class);
        $failedAt = $envelope->last(RedeliveryStamp::class)?->getRedeliveredAt();

        return new QueuedMessage(
            id: (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
            transport: $transport,
            class: $envelope->getMessage()::class,
            error: $error?->getExceptionMessage(),
            errorClass: $error?->getExceptionClass(),
            failedAt: null === $failedAt ? null : \DateTimeImmutable::createFromInterface($failedAt),
            originalTransport: $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
        );
    }

    private function transport(string $name): TransportInterface
    {
        $transport = $this->transports->get($name);
        \assert($transport instanceof TransportInterface);

        return $transport;
    }
}
