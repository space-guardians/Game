<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Galaxy;
use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\GalaxyRepository;
use App\Repository\GalaxyShapeTemplateRepository;
use App\Service\Universe\GalaxyGenerator;
use App\Service\Universe\SystemPlacer;
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
        private GalaxyShapeTemplateRepository $templates,
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
        #[Option('Nombre de branches de la spirale (forme par défaut)')]
        int $arms = 4,
        #[Option('Nom d\'un gabarit de forme du panneau d\'administration (remplace --arms)')]
        ?string $template = null,
    ): int {
        $number ??= $this->galaxies->nextNumber();
        if ($this->galaxies->numberExists($number)) {
            $io->error(\sprintf('La galaxie n°%d existe déjà.', $number));

            return Command::FAILURE;
        }

        $shapeTemplate = null;
        if (null !== $template) {
            $shapeTemplate = $this->templates->findOneByName($template);
            if (null === $shapeTemplate) {
                $io->error(\sprintf('Gabarit de forme inconnu : « %s ».', $template));

                return Command::FAILURE;
            }
        }

        $seed ??= random_int(1, 2_147_483_647);
        $start = hrtime(true);

        try {
            $galaxy = $this->generator->generate(
                $number,
                $name ?? \sprintf('Galaxie %d', $number),
                $seed,
                $shapeTemplate?->toShape() ?? new SpiralGalaxyShape(arms: $arms),
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
        $io->note(\sprintf(
            'Pour régénérer la même galaxie : --seed=%d --systems=%d %s',
            $seed,
            $systems,
            null !== $shapeTemplate
                ? \sprintf('--template="%s" (tant que le gabarit n\'est pas modifié)', $shapeTemplate->getName())
                : '--arms=' . $arms,
        ));

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
