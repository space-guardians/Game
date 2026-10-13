<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ranking\Rankings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ranking:refresh', description: 'Recalcule les scores et les classements des empires')]
final readonly class RefreshRankingsCommand
{
    public function __construct(
        private Rankings $rankings,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d empire(s) classé(s).', $this->rankings->refresh()));

        return Command::SUCCESS;
    }
}
