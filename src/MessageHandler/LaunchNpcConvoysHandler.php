<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\LaunchNpcConvoys;
use App\Service\Pve\NpcConvoys;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class LaunchNpcConvoysHandler
{
    public function __construct(
        private NpcConvoys $convoys,
    ) {}

    public function __invoke(LaunchNpcConvoys $message): void
    {
        $this->convoys->launchDue();
    }
}
