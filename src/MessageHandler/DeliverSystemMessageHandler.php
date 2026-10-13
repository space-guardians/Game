<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DeliverSystemMessage;
use App\Service\Messaging\SystemMessenger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeliverSystemMessageHandler
{
    public function __construct(
        private SystemMessenger $messenger,
    ) {}

    public function __invoke(DeliverSystemMessage $delivery): void
    {
        $this->messenger->deliverAsync($delivery);
    }
}
