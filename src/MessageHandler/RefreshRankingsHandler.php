<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshRankings;
use App\Service\Ranking\Rankings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshRankingsHandler
{
    public function __construct(
        private Rankings $rankings,
    ) {}

    public function __invoke(RefreshRankings $message): void
    {
        $this->rankings->refresh();
    }
}
