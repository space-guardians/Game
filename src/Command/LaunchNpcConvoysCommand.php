<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Pve\NpcConvoys;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:npc:convoys', description: 'Publie les convois à escorter des guildes marchandes dont l’intervalle est écoulé')]
final readonly class LaunchNpcConvoysCommand
{
    public function __construct(
        private NpcConvoys $convoys,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d convoi(s) publié(s).', $this->convoys->launchDue()));

        return Command::SUCCESS;
    }
}
