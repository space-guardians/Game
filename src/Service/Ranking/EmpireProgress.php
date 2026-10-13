<?php

declare(strict_types=1);

namespace App\Service\Ranking;

use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Entity\EmpireSnapshot;
use App\Entity\FleetShip;
use App\Entity\Planet;
use App\Entity\PlanetShip;
use App\Enum\Fleet\ShipCategory;
use App\Model\Ranking\ScoreBreakdown;
use App\Repository\EmpireSnapshotRepository;
use App\Service\Economy\PlanetEconomy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Suivi de la progression des empires (§5.6.1) : à chaque prise (`TakeEmpireSnapshots`, planifiée), les scores sont
 * recalculés puis un instantané de chaque empire est enregistré au même instant — score par catégorie, production
 * horaire de ses planètes, vaisseaux (défenses exclues) et planètes. Les instantanés de plus de `RETENTION_DAYS`
 * jours sont effacés.
 */
final readonly class EmpireProgress
{
    public const int RETENTION_DAYS = 365;

    /** Planètes chargées à la fois pour calculer la production */
    private const int BATCH = 200;

    public function __construct(
        private Rankings $rankings,
        private PlanetEconomy $economy,
        private EmpireSnapshotRepository $snapshots,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** Prend un instantané de chaque empire ; renvoie le nombre d'empires concernés */
    public function snapshot(): int
    {
        $this->rankings->refresh();
        // À la seconde, comme en base : tous les instantanés de la prise partagent exactement cet instant
        $now = $this->clock->now();
        $takenAt = $now->setTimestamp($now->getTimestamp());
        $production = $this->production();
        $ships = $this->counts('SELECT IDENTITY(p.owner) AS empire, SUM(s.quantity) AS n FROM ' . PlanetShip::class . ' s JOIN s.planet p JOIN s.type t WHERE p.owner IS NOT NULL AND t.category != :defense GROUP BY p.owner', ['defense' => ShipCategory::Defense]);
        foreach ($this->counts('SELECT IDENTITY(f.empire) AS empire, SUM(s.quantity) AS n FROM ' . FleetShip::class . ' s JOIN s.fleet f GROUP BY f.empire') as $empire => $n) {
            $ships[$empire] = ($ships[$empire] ?? 0) + $n;
        }
        $planets = $this->counts('SELECT IDENTITY(p.owner) AS empire, COUNT(p.id) AS n FROM ' . Planet::class . ' p WHERE p.owner IS NOT NULL GROUP BY p.owner');

        /** @var list<array{empire: int|string, economy: int, research: int, military: int, exploration: int, total: int}> $scores */
        $scores = $this->entityManager->createQuery('SELECT IDENTITY(s.empire) AS empire, s.economy, s.research, s.military, s.exploration, s.total FROM ' . EmpireScore::class . ' s')->getArrayResult();
        foreach ($scores as $score) {
            $id = (int) $score['empire'];
            $this->entityManager->persist(new EmpireSnapshot(
                $this->entityManager->getReference(Empire::class, $id),
                $takenAt,
                new ScoreBreakdown($score['economy'], $score['research'], $score['military'], $score['exploration'], $score['total']),
                (int) round($production[$id] ?? 0.0),
                $ships[$id] ?? 0,
                $planets[$id] ?? 0,
            ));
        }
        $this->entityManager->flush();
        $this->snapshots->purgeBefore($takenAt->modify(\sprintf('-%d days', self::RETENTION_DAYS)));

        return \count($scores);
    }

    /** @return array<int, float> production horaire (métal + cristal + deutérium) de toutes les planètes, par empire */
    private function production(): array
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->entityManager->createQuery('SELECT p.id FROM ' . Planet::class . ' p WHERE p.owner IS NOT NULL ORDER BY p.id')->getSingleColumnResult());
        $production = [];
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            /** @var list<Planet> $planets */
            $planets = $this->entityManager->createQuery('SELECT p, b FROM ' . Planet::class . ' p LEFT JOIN p.buildings b WHERE p.id IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getResult();
            foreach ($planets as $planet) {
                $rates = $this->economy->output($planet)->hourlyProduction;
                $empire = (int) $planet->getOwner()?->getId();
                $production[$empire] = ($production[$empire] ?? 0.0) + $rates->metal + $rates->crystal + $rates->deuterium;
            }
        }

        return $production;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<int, int>
     */
    private function counts(string $dql, array $parameters = []): array
    {
        $counts = [];
        $query = $this->entityManager->createQuery($dql);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        /** @var list<array{empire: int|string, n: int|string}> $rows */
        $rows = $query->getArrayResult();
        foreach ($rows as $row) {
            $counts[(int) $row['empire']] = (int) $row['n'];
        }

        return $counts;
    }
}
