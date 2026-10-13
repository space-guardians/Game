<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Ranking\ScoreBreakdown;
use App\Repository\EmpireSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instantané périodique d'un empire (§5.6.1, suivi de la progression) : score par catégorie, production horaire,
 * vaisseaux et planètes à un instant. Tous les instantanés d'une même prise partagent `takenAt`, ce qui permet de
 * comparer un empire à la moyenne et au top 10 du moment. Donnée calculée, non auditée.
 */
#[ORM\Entity(repositoryClass: EmpireSnapshotRepository::class)]
#[ORM\UniqueConstraint(name: 'empire_snapshot_unique', fields: ['empire', 'takenAt'])]
#[ORM\Index(name: 'empire_snapshot_taken_idx', fields: ['takenAt'])]
class EmpireSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private readonly int $total;

    #[ORM\Column]
    private readonly int $economy;

    #[ORM\Column]
    private readonly int $research;

    #[ORM\Column]
    private readonly int $military;

    #[ORM\Column]
    private readonly int $exploration;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\Column]
        private readonly \DateTimeImmutable $takenAt,
        ScoreBreakdown $score,
        /** Production horaire de toutes ses planètes, métal + cristal + deutérium */
        #[ORM\Column]
        private readonly int $production,
        /** Vaisseaux sur ses planètes et dans ses flottes, défenses exclues */
        #[ORM\Column]
        private readonly int $ships,
        #[ORM\Column]
        private readonly int $planets,
    ) {
        $this->total = $score->total;
        $this->economy = $score->economy;
        $this->research = $score->research;
        $this->military = $score->military;
        $this->exploration = $score->exploration;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getTakenAt(): \DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function getTotal(): int
    {
        return $this->total;
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

    public function getProduction(): int
    {
        return $this->production;
    }

    public function getShips(): int
    {
        return $this->ships;
    }

    public function getPlanets(): int
    {
        return $this->planets;
    }
}
