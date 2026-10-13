<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Empire;
use App\Entity\EmpireSnapshot;
use App\Enum\Ranking\ProgressMetric;
use App\Repository\EmpireSnapshotRepository;
use Psr\Clock\ClockInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Graphiques de progression d'un empire sur la fiche joueur du panneau (§5.6.1) : score, économie, flotte et planètes
 * au fil des instantanés, comparés à la moyenne de tous les empires et à celle du top 10 du classement global au même
 * instant ; détail du score par catégorie.
 */
final readonly class ProgressCharts
{
    public const int DEFAULT_DAYS = 30;

    /** Couleurs de la charte (jetons --sg-gold, --sg-cyan, --sg-violet, --sg-success) */
    private const string EMPIRE_COLOR = '#f2b84b';

    private const string AVERAGE_COLOR = '#6fdcee';

    private const string TOP_COLOR = '#b9a3ff';

    private const array CATEGORY_COLORS = [
        'economy' => '#f2b84b',
        'research' => '#6fdcee',
        'military' => '#ff8a8a',
        'exploration' => '#6ee0a6',
    ];

    /** Graphiques comparés : grandeur → titre */
    private const array COMPARED = [
        'total' => 'Score global',
        'production' => 'Économie : production horaire',
        'ships' => 'Flotte : vaisseaux',
        'planets' => 'Planètes',
    ];

    public function __construct(
        private EmpireSnapshotRepository $snapshots,
        private ChartBuilderInterface $builder,
        private ClockInterface $clock,
    ) {}

    /**
     * Graphiques de l'empire sur les derniers jours, par titre ; vide s'il n'a encore aucun instantané.
     *
     * @return array<string, Chart>
     */
    public function of(Empire $empire, int $days = self::DEFAULT_DAYS): array
    {
        $since = $this->clock->now()->modify(\sprintf('-%d days', $days));
        $snapshots = $this->snapshots->findSince($empire, $since);
        if ([] === $snapshots) {
            return [];
        }
        $benchmarks = $this->snapshots->benchmarksSince($since);
        $labels = array_map(static fn(EmpireSnapshot $snapshot): string => $snapshot->getTakenAt()->format('d/m H\h'), $snapshots);

        $charts = [];
        foreach (self::COMPARED as $metric => $title) {
            $metric = ProgressMetric::from($metric);
            $benchmark = static fn(EmpireSnapshot $snapshot, string $kind): float => round($benchmarks[$snapshot->getTakenAt()->format('Y-m-d H:i:s')][$metric->value][$kind] ?? 0.0, 1);
            $charts[$title] = $this->line($labels, [
                $this->dataset($empire->getName(), self::EMPIRE_COLOR, array_map(static fn(EmpireSnapshot $snapshot): int => self::value($snapshot, $metric), $snapshots)),
                $this->dataset('Moyenne des empires', self::AVERAGE_COLOR, array_map(static fn(EmpireSnapshot $snapshot): float => $benchmark($snapshot, 'average'), $snapshots), dashed: true),
                $this->dataset(\sprintf('Moyenne du top %d', EmpireSnapshotRepository::TOP), self::TOP_COLOR, array_map(static fn(EmpireSnapshot $snapshot): float => $benchmark($snapshot, 'top'), $snapshots), dashed: true),
            ]);
        }
        $categories = [];
        foreach (self::CATEGORY_COLORS as $metric => $color) {
            $metric = ProgressMetric::from($metric);
            $categories[] = $this->dataset($metric->label(), $color, array_map(static fn(EmpireSnapshot $snapshot): int => self::value($snapshot, $metric), $snapshots));
        }
        $charts['Score par catégorie'] = $this->line($labels, $categories);

        return $charts;
    }

    public static function value(EmpireSnapshot $snapshot, ProgressMetric $metric): int
    {
        return match ($metric) {
            ProgressMetric::Total => $snapshot->getTotal(),
            ProgressMetric::Economy => $snapshot->getEconomy(),
            ProgressMetric::Research => $snapshot->getResearch(),
            ProgressMetric::Military => $snapshot->getMilitary(),
            ProgressMetric::Exploration => $snapshot->getExploration(),
            ProgressMetric::Production => $snapshot->getProduction(),
            ProgressMetric::Ships => $snapshot->getShips(),
            ProgressMetric::Planets => $snapshot->getPlanets(),
        };
    }

    /**
     * @param list<string>               $labels
     * @param list<array<string, mixed>> $datasets
     */
    private function line(array $labels, array $datasets): Chart
    {
        return $this->builder->createChart(Chart::TYPE_LINE)
            ->setData(['labels' => $labels, 'datasets' => $datasets])
            ->setOptions([
                'animation' => false,
                'interaction' => ['mode' => 'index', 'intersect' => false],
                'scales' => ['y' => ['beginAtZero' => true]],
            ]);
    }

    /**
     * @param list<int|float> $data
     *
     * @return array<string, mixed>
     */
    private function dataset(string $label, string $color, array $data, bool $dashed = false): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderDash' => $dashed ? [6, 4] : [],
            'pointRadius' => 2,
            'tension' => 0.2,
        ];
    }
}
