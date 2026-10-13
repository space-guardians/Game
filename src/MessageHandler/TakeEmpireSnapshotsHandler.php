<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TakeEmpireSnapshots;
use App\Service\Ranking\EmpireProgress;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TakeEmpireSnapshotsHandler
{
    public function __construct(
        private EmpireProgress $progress,
    ) {}

    public function __invoke(TakeEmpireSnapshots $message): void
    {
        $this->progress->snapshot();
    }
}
