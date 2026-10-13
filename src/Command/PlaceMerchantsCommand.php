<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GalaxyRepository;
use App\Service\Market\Merchants;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:merchant:place', description: 'Place les marchands d’une galaxie générée avant leur introduction')]
final readonly class PlaceMerchantsCommand
{
    public function __construct(
        private GalaxyRepository $galaxies,
        private Merchants $merchants,
        private EntityManagerInterface $entityManager,
        private Randomizer $randomizer,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Numéro de la galaxie')]
        int $galaxy,
    ): int {
        $found = $this->galaxies->findOneBy(['number' => $galaxy]);
        if (null === $found) {
            $io->error(\sprintf('Aucune galaxie n°%d.', $galaxy));

            return Command::INVALID;
        }
        try {
            $placed = $this->merchants->place($found, $this->randomizer);
        } catch (\LogicException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        $this->entityManager->flush();
        $io->success(\sprintf('%d marchand(s) placé(s) dans la galaxie n°%d.', \count($placed), $galaxy));

        return Command::SUCCESS;
    }
}
