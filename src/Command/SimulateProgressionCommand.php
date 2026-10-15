<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Economy\EconomySettings;
use App\Repository\BuildingTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Economy\BuildingRules;
use App\Service\Research\ResearchRules;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:balance:progression', description: 'Coûts et durées par niveau des bâtiments et des recherches (passe d’équilibrage)')]
final readonly class SimulateProgressionCommand
{
    public function __construct(
        private BuildingTypeRepository $buildingTypes,
        private TechnologyRepository $technologies,
        private BuildingRules $buildingRules,
        private ResearchRules $researchRules,
        private EconomySettings $settings,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Niveaux affichés')]
        int $levels = 10,
        #[Option('Niveau de l’usine de robots (durées des bâtiments)')]
        int $robots = 0,
        #[Option('Niveaux de laboratoire cumulés (durées des recherches)')]
        int $laboratories = 1,
    ): int {
        $levels = max(1, min(40, $levels));
        $io->title(\sprintf('Vitesse d’univers : %s', $this->settings->universeSpeed));
        $io->section('Bâtiments');
        $rows = [];
        foreach ($this->buildingTypes->findAllOrdered() as $type) {
            foreach ([1, (int) ceil($levels / 2), $levels] as $level) {
                $cost = $this->buildingRules->cost($type, $level);
                $rows[] = [$type->getName(), $level, self::amounts($cost->metal, $cost->crystal, $cost->deuterium), self::duration($this->buildingRules->constructionSeconds($cost, $robots, 0, $this->settings->universeSpeed))];
            }
        }
        $io->table(['Bâtiment', 'Niveau', 'Coût (M / C / D)', 'Durée'], $rows);
        $io->section('Recherches');
        $rows = [];
        foreach ($this->technologies->findBy([], ['name' => 'ASC']) as $technology) {
            foreach ([1, (int) ceil($levels / 2), $levels] as $level) {
                $cost = $this->researchRules->cost($technology, $level);
                $rows[] = [$technology->getName(), $level, self::amounts($cost->metal, $cost->crystal, $cost->deuterium), self::duration($this->researchRules->durationSeconds($cost, $laboratories, $this->settings->universeSpeed))];
            }
        }
        $io->table(['Recherche', 'Niveau', 'Coût (M / C / D)', 'Durée'], $rows);

        return Command::SUCCESS;
    }

    private static function amounts(float $metal, float $crystal, float $deuterium): string
    {
        return \sprintf('%s / %s / %s', number_format($metal, 0, ',', ' '), number_format($crystal, 0, ',', ' '), number_format($deuterium, 0, ',', ' '));
    }

    private static function duration(int $seconds): string
    {
        return \sprintf('%d h %02d min %02d s', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
