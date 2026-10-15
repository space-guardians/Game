<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Pve\BossFleets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:npc:boss-fleets', description: 'Recalcule la flotte des civilisations « boss » dont l’intervalle est écoulé')]
final readonly class RecalculateBossFleetsCommand
{
    public function __construct(
        private BossFleets $fleets,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d flotte(s) de boss recalculée(s).', $this->fleets->recalculateDue()));

        return Command::SUCCESS;
    }
}
