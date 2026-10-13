<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ranking\EmpireProgress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:progress:snapshot', description: 'Enregistre un instantané de chaque empire (suivi de la progression)')]
final readonly class TakeEmpireSnapshotsCommand
{
    public function __construct(
        private EmpireProgress $progress,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d instantané(s) enregistré(s).', $this->progress->snapshot()));

        return Command::SUCCESS;
    }
}
