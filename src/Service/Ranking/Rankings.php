<?php

declare(strict_types=1);

namespace App\Service\Ranking;

use App\Entity\BuildingType;
use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Entity\ExplorationEventInstance;
use App\Entity\FleetShip;
use App\Entity\Planet;
use App\Entity\PlanetBuilding;
use App\Entity\PlanetShip;
use App\Entity\Research;
use App\Entity\ShipType;
use App\Entity\Technology;
use App\Model\Ranking\ScoreBreakdown;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Recalcul des scores de tous les empires (§4.11), par requêtes agrégées : bâtiments des planètes, recherches,
 * vaisseaux et défenses (sur les planètes et dans les flottes), colonies et événements d'exploration vécus. Lancé
 * périodiquement (`RefreshRankings`) ; le score global est recopié dans `Empire::score`.
 */
final readonly class Rankings
{
    public function __construct(
        private RankingRules $rules,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** Recalcule et enregistre le score de chaque empire ; renvoie le nombre d'empires classés */
    public function refresh(): int
    {
        $buildings = $this->levelCosts(
            'SELECT IDENTITY(p.owner) AS empire, IDENTITY(b.type) AS item, b.level AS level FROM ' . PlanetBuilding::class . ' b JOIN b.planet p WHERE p.owner IS NOT NULL AND b.level > 0',
            BuildingType::class,
        );
        $research = $this->levelCosts(
            'SELECT IDENTITY(r.empire) AS empire, IDENTITY(r.technology) AS item, r.level AS level FROM ' . Research::class . ' r WHERE r.level > 0',
            Technology::class,
        );
        $ships = $this->shipCosts();
        $colonies = $this->counts('SELECT IDENTITY(p.owner) AS empire, COUNT(p.id) AS n FROM ' . Planet::class . ' p WHERE p.owner IS NOT NULL GROUP BY p.owner');
        $explorations = $this->counts('SELECT IDENTITY(x.empire) AS empire, COUNT(x.id) AS n FROM ' . ExplorationEventInstance::class . ' x GROUP BY x.empire');

        $now = $this->clock->now();
        /** @var array<int, EmpireScore> $existing */
        $existing = [];
        foreach ($this->entityManager->getRepository(EmpireScore::class)->findAll() as $score) {
            $existing[(int) $score->getEmpire()->getId()] = $score;
        }
        /** @var list<int> $empireIds */
        $empireIds = array_map('intval', $this->entityManager->createQuery('SELECT e.id FROM ' . Empire::class . ' e')->getSingleColumnResult());
        foreach ($empireIds as $id) {
            $breakdown = $this->rules->score(
                $buildings[$id] ?? 0.0,
                $research[$id] ?? 0.0,
                $ships[$id] ?? 0.0,
                max(0, ($colonies[$id] ?? 0) - 1),
                $explorations[$id] ?? 0,
            );
            $this->store($id, $breakdown, $existing[$id] ?? null, $now);
        }
        $this->entityManager->flush();

        return \count($empireIds);
    }

    private function store(int $empireId, ScoreBreakdown $breakdown, ?EmpireScore $score, \DateTimeImmutable $now): void
    {
        if (null === $score) {
            $empire = $this->entityManager->getReference(Empire::class, $empireId);
            $score = new EmpireScore($empire, $now);
            $this->entityManager->persist($score);
        }
        $score->update($breakdown, $now);
        // Recopie du score global sur l'empire, sans passer par l'historique des modifications (donnée calculée)
        $this->entityManager->createQuery('UPDATE ' . Empire::class . ' e SET e.score = :score WHERE e.id = :id')
            ->setParameter('score', $breakdown->total)
            ->setParameter('id', $empireId)
            ->execute();
    }

    /**
     * Ressources investies par empire, d'après des lignes (empire, élément, niveau).
     *
     * @param class-string<BuildingType|Technology> $class
     *
     * @return array<int, float>
     */
    private function levelCosts(string $dql, string $class): array
    {
        /** @var array<int, BuildingType|Technology> $items */
        $items = [];
        foreach ($this->entityManager->getRepository($class)->findAll() as $item) {
            $items[(int) $item->getId()] = $item;
        }
        $costs = [];
        /** @var list<array{empire: int|string, item: int|string, level: int|string}> $rows */
        $rows = $this->entityManager->createQuery($dql)->getArrayResult();
        foreach ($rows as $row) {
            $item = $items[(int) $row['item']] ?? null;
            if (null === $item) {
                continue;
            }
            $empire = (int) $row['empire'];
            $costs[$empire] = ($costs[$empire] ?? 0.0) + $this->rules->levelsCost($item->getBaseCost(), $item->getCostFactor(), (int) $row['level']);
        }

        return $costs;
    }

    /** @return array<int, float> ressources investies dans les vaisseaux et défenses existants, par empire */
    private function shipCosts(): array
    {
        /** @var array<int, ShipType> $types */
        $types = [];
        foreach ($this->entityManager->getRepository(ShipType::class)->findAll() as $type) {
            $types[(int) $type->getId()] = $type;
        }
        /** @var list<array{empire: int|string, item: int|string, quantity: int|string}> $rows */
        $rows = [
            ...$this->entityManager->createQuery('SELECT IDENTITY(p.owner) AS empire, IDENTITY(s.type) AS item, SUM(s.quantity) AS quantity FROM ' . PlanetShip::class . ' s JOIN s.planet p WHERE p.owner IS NOT NULL GROUP BY p.owner, s.type')->getArrayResult(),
            ...$this->entityManager->createQuery('SELECT IDENTITY(f.empire) AS empire, IDENTITY(s.type) AS item, SUM(s.quantity) AS quantity FROM ' . FleetShip::class . ' s JOIN s.fleet f GROUP BY f.empire, s.type')->getArrayResult(),
        ];
        $costs = [];
        foreach ($rows as $row) {
            $type = $types[(int) $row['item']] ?? null;
            if (null === $type) {
                continue;
            }
            $empire = (int) $row['empire'];
            $costs[$empire] = ($costs[$empire] ?? 0.0) + $this->rules->unitsCost($type->getCost(), (int) $row['quantity']);
        }

        return $costs;
    }

    /** @return array<int, int> */
    private function counts(string $dql): array
    {
        $counts = [];
        /** @var list<array{empire: int|string, n: int|string}> $rows */
        $rows = $this->entityManager->createQuery($dql)->getArrayResult();
        foreach ($rows as $row) {
            $counts[(int) $row['empire']] = (int) $row['n'];
        }

        return $counts;
    }
}
