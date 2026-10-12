<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EspionageReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rapport d'espionnage (§4.9) : ce que des sondes ont vu à leur arrivée sur une planète ou auprès d'une flotte —
 * ressources, flottes, défenses, bâtiments, recherches —, chaque section n'apparaissant qu'au-delà d'un niveau de
 * détail (EspionageRules). Données figées (noms recopiés), lisibles par l'empire espion seul. Non audité.
 */
#[ORM\Entity(repositoryClass: EspionageReportRepository::class)]
#[ORM\Index(name: 'espionage_report_empire_idx', fields: ['empire', 'spiedAt'])]
final class EspionageReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, mixed> $sections sections révélées (ressources, flottes, défenses, bâtiments, recherches)
     */
    public function __construct(
        /** Empire espion, seul destinataire */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\Column(length: 80)]
        private readonly string $locationLabel,
        /** Empire espionné (propriétaire de la planète ou des flottes), tel que nommé à l'instant */
        #[ORM\Column(length: Empire::NAME_MAX_LENGTH, nullable: true)]
        private readonly ?string $targetName,
        #[ORM\Column]
        private readonly \DateTimeImmutable $spiedAt,
        #[ORM\Column]
        private readonly int $score,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $sections,
        /** Chance de détection courue, et issue du tirage (#53) */
        #[ORM\Column]
        private readonly float $detectionChance = 0.0,
        #[ORM\Column]
        private readonly bool $detected = false,
    ) {}

    public function getDetectionChance(): float
    {
        return $this->detectionChance;
    }

    public function isDetected(): bool
    {
        return $this->detected;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getLocationLabel(): string
    {
        return $this->locationLabel;
    }

    public function getTargetName(): ?string
    {
        return $this->targetName;
    }

    public function getSpiedAt(): \DateTimeImmutable
    {
        return $this->spiedAt;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    /** @return array<string, mixed> */
    public function getSections(): array
    {
        return $this->sections;
    }

    public function reveals(string $section): bool
    {
        return \array_key_exists($section, $this->sections);
    }
}
