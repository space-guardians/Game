<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Planet;
use App\Repository\GalaxyRepository;
use App\Repository\NpcBehaviorProfileRepository;
use App\Service\Pve\NpcCivilisations;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:npc:found', description: 'Fonde une civilisation non-joueuse sur une planète libre tirée au sort')]
final readonly class FoundNpcCivilisationCommand
{
    public function __construct(
        private NpcBehaviorProfileRepository $profiles,
        private GalaxyRepository $galaxies,
        private NpcCivilisations $civilisations,
        private EntityManagerInterface $entityManager,
        private Randomizer $randomizer,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Code du profil de comportement (ex. minor_raider)')]
        string $profile,
        #[Argument('Nom de la civilisation')]
        string $name,
        #[Option('Numéro de la galaxie')]
        int $galaxy = 1,
    ): int {
        $found = $this->profiles->findOneByCode($profile);
        $target = $this->galaxies->findOneBy(['number' => $galaxy]);
        if (null === $found || null === $target) {
            $io->error(null === $found ? \sprintf('Aucun profil « %s ».', $profile) : \sprintf('Aucune galaxie n°%d.', $galaxy));

            return Command::INVALID;
        }
        /** @var list<Planet> $free */
        $free = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Planet::class, 'p')
            ->join('p.system', 's')
            ->where('s.galaxy = :galaxy')
            ->andWhere('p.owner IS NULL AND p.alliance IS NULL')
            ->setParameter('galaxy', $target)
            ->getQuery()
            ->getResult();
        if ([] === $free) {
            $io->error('Aucune planète libre dans cette galaxie.');

            return Command::FAILURE;
        }
        $planet = $free[$this->randomizer->getInt(0, \count($free) - 1)];
        $this->civilisations->found($found, $name, $planet);
        $io->success(\sprintf('Civilisation « %s » (%s) fondée sur %s.', $name, $found->getName(), $planet));

        return Command::SUCCESS;
    }
}
