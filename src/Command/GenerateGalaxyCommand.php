<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GalaxyGeneration;
use App\Exception\Universe\GalaxyNumberTaken;
use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\GalaxyShapeTemplateRepository;
use App\Service\Universe\GalaxyCreator;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Génère une galaxie et l'ajoute à l'univers (GalaxyCreator, partagé avec le panneau d'administration).
 * La graine est affichée : la relancer avec --seed reproduit exactement la même galaxie.
 *
 * @see §5.5 du cahier des charges
 */
#[AsCommand(name: 'app:galaxy:generate', description: 'Génère une galaxie (systèmes et planètes) et l\'enregistre')]
final readonly class GenerateGalaxyCommand
{
    public function __construct(
        private GalaxyCreator $creator,
        private GalaxyShapeTemplateRepository $templates,
        private Randomizer $randomizer,
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
        $shapeTemplate = null;
        if (null !== $template) {
            $shapeTemplate = $this->templates->findOneByName($template);
            if (null === $shapeTemplate) {
                $io->error(\sprintf('Gabarit de forme inconnu : « %s ».', $template));

                return Command::FAILURE;
            }
        }

        $seed ??= $this->randomizer->getInt(1, GalaxyGeneration::MAX_SEED);
        $start = hrtime(true);

        try {
            $created = $this->creator->create($number, $name, $seed, $shapeTemplate?->toShape() ?? new SpiralGalaxyShape(arms: $arms), $systems);
        } catch (GalaxyNumberTaken $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $galaxy = $created->galaxy;
        $io->success(\sprintf('Galaxie n°%d « %s » générée.', $galaxy->getNumber(), $galaxy->getName()));
        $io->definitionList(
            ['Graine' => (string) $seed],
            ['Systèmes' => (string) $created->systems],
            ['Planètes' => (string) $created->planets],
            ['Rayon' => \sprintf('%.0f', $created->radius)],
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
}
