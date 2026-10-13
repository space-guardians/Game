<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ranking\RankingCategory;
use App\Model\Ranking\ScoreBreakdown;
use App\Repository\EmpireScoreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Score d'un empire par catégorie (§4.11), recalculé périodiquement (`Rankings::refresh()`) : les classements se lisent
 * ici. Donnée calculée, non auditée.
 */
#[ORM\Entity(repositoryClass: EmpireScoreRepository::class)]
#[ORM\Index(name: 'empire_score_total_idx', fields: ['total'])]
class EmpireScore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $economy = 0;

    #[ORM\Column]
    private int $research = 0;

    #[ORM\Column]
    private int $military = 0;

    #[ORM\Column]
    private int $exploration = 0;

    #[ORM\Column]
    private int $total = 0;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\Column]
        private \DateTimeImmutable $computedAt,
    ) {}

    public function update(ScoreBreakdown $score, \DateTimeImmutable $at): void
    {
        $this->economy = $score->economy;
        $this->research = $score->research;
        $this->military = $score->military;
        $this->exploration = $score->exploration;
        $this->total = $score->total;
        $this->computedAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getEconomy(): int
    {
        return $this->economy;
    }

    public function getResearch(): int
    {
        return $this->research;
    }

    public function getMilitary(): int
    {
        return $this->military;
    }

    public function getExploration(): int
    {
        return $this->exploration;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function points(RankingCategory $category): int
    {
        return match ($category) {
            RankingCategory::Global => $this->total,
            RankingCategory::Economy => $this->economy,
            RankingCategory::Research => $this->research,
            RankingCategory::Military => $this->military,
            RankingCategory::Exploration => $this->exploration,
        };
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }
}
