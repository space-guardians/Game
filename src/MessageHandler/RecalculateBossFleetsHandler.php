<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RecalculateBossFleets;
use App\Service\Pve\BossFleets;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecalculateBossFleetsHandler
{
    public function __construct(
        private BossFleets $fleets,
    ) {}

    public function __invoke(RecalculateBossFleets $message): void
    {
        $this->fleets->recalculateDue();
    }
}
