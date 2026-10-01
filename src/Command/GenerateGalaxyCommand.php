<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Galaxy;
use App\Repository\GalaxyRepository;
use App\Universe\Generation\GalaxyGenerator;
use App\Universe\Generation\SpiralGalaxyShape;
use App\Universe\Generation\SystemPlacer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Génère une galaxie et l'ajoute à l'univers, sans toucher aux galaxies existantes (§2.1).
 * La graine est affichée : la relancer avec --seed reproduit exactement la même galaxie.
 *
 * @see §5.5 du cahier des charges
 */
#[AsCommand(name: 'app:galaxy:generate', description: 'Génère une galaxie (systèmes et planètes) et l\'enregistre')]
final readonly class GenerateGalaxyCommand
{
    public function __construct(
        private GalaxyGenerator $generator,
        private GalaxyRepository $galaxies,
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Numéro de la galaxie (par défaut : le suivant)')]
        ?int $number = null,
        #[Option('Nom de la galaxie (par défaut : « Galaxie <numéro> »)')]
        ?string $name = null,
        #[Option('Graine de génération (par défaut : aléatoire)')]
        ?int $seed = null,
        #[Option('Nombre de systèmes stellaires')]
        int $systems = 1000,
        #[Option('Nombre de branches de la spirale')]
        int $arms = 4,
    ): int {
        $number ??= $this->galaxies->nextNumber();
        if ($this->galaxies->numberExists($number)) {
            $io->error(\sprintf('La galaxie n°%d existe déjà.', $number));

            return Command::FAILURE;
        }

        $seed ??= random_int(1, 2_147_483_647);
        $start = hrtime(true);

        try {
            $galaxy = $this->generator->generate(
                $number,
                $name ?? \sprintf('Galaxie %d', $number),
                $seed,
                new SpiralGalaxyShape(arms: $arms),
                $systems,
                SystemPlacer::DEFAULT_MIN_DISTANCE,
            );
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $planets = $this->persist($galaxy);

        $io->success(\sprintf('Galaxie n°%d « %s » générée.', $galaxy->getNumber(), $galaxy->getName()));
        $io->definitionList(
            ['Graine' => (string) $seed],
            ['Systèmes' => (string) $galaxy->getSystems()->count()],
            ['Planètes' => (string) $planets],
            ['Rayon' => \sprintf('%.0f', $this->radius($galaxy))],
            ['Durée' => \sprintf('%.1f s', (hrtime(true) - $start) / 1e9)],
        );
        $io->note(\sprintf('Pour régénérer la même galaxie : --seed=%d --systems=%d --arms=%d', $seed, $systems, $arms));

        return Command::SUCCESS;
    }

    /** @return int nombre de planètes enregistrées */
    private function persist(Galaxy $galaxy): int
    {
        $planets = 0;
        $this->entityManager->persist($galaxy);
        foreach ($galaxy->getSystems() as $system) {
            $this->entityManager->persist($system);
            foreach ($system->getPlanets() as $planet) {
                $this->entityManager->persist($planet);
                ++$planets;
            }
        }
        $this->entityManager->flush();

        return $planets;
    }

    private function radius(Galaxy $galaxy): float
    {
        $radius = 0.0;
        foreach ($galaxy->getSystems() as $system) {
            $radius = max($radius, $system->getPosition()->distanceFromCenter());
        }

        return $radius;
    }
}
