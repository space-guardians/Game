<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Universe\GenerationStatus;
use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\GalaxyGenerationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demande de génération d'une galaxie depuis le panneau d'administration, exécutée en arrière-plan par le
 * worker (message GenerateGalaxy), et son suivi. La forme est figée à la demande : la génération reste
 * rejouable (graine + forme + nombre de systèmes) même si le gabarit est modifié ensuite.
 *
 * @see §5.5 et §5.6.1 du cahier des charges
 */
#[ORM\Entity(repositoryClass: GalaxyGenerationRepository::class)]
#[ORM\Index(name: 'galaxy_generation_requested_at_idx', fields: ['requestedAt'])]
final class GalaxyGeneration
{
    /** Graine maximale (entier PostgreSQL) */
    public const int MAX_SEED = 2_147_483_647;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom de la galaxie ; vide : « Galaxie <numéro> » */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column]
    #[Assert\Range(notInRangeMessage: 'Entre {{ min }} et {{ max }} systèmes.', min: 10, max: 5_000)]
    private int $systemCount = 1_000;

    /** Graine ; vide à la demande : tirée au hasard à l'enregistrement */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(notInRangeMessage: 'La graine est un entier entre {{ min }} et {{ max }}.', min: 1, max: self::MAX_SEED)]
    private ?int $seed = null;

    /** Gabarit choisi ; vide : forme par défaut (spirale à 4 branches) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?GalaxyShapeTemplate $shapeTemplate = null;

    /** @var array{arms: int, armTightness: float, armWidth: float, coreRadius: float, diskScale: float, interArmDensity: float}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shape = null;

    #[ORM\Column(length: 20, enumType: GenerationStatus::class)]
    private GenerationStatus $status = GenerationStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Galaxy $galaxy = null;

    #[ORM\Column(nullable: true)]
    private ?int $planetCount = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?AdminUser $requestedBy,
        #[ORM\Column]
        private readonly \DateTimeImmutable $requestedAt,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequestedBy(): ?AdminUser
    {
        return $this->requestedBy;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $name = null === $name ? null : trim($name);
        $this->name = '' === $name ? null : $name;
    }

    public function getSystemCount(): int
    {
        return $this->systemCount;
    }

    public function setSystemCount(int $systemCount): void
    {
        $this->systemCount = $systemCount;
    }

    public function getSeed(): ?int
    {
        return $this->seed;
    }

    public function setSeed(?int $seed): void
    {
        $this->seed = $seed;
    }

    public function getShapeTemplate(): ?GalaxyShapeTemplate
    {
        return $this->shapeTemplate;
    }

    public function setShapeTemplate(?GalaxyShapeTemplate $shapeTemplate): void
    {
        $this->shapeTemplate = $shapeTemplate;
    }

    /** Fige la demande avant sa mise en file : graine tirée si vide, forme recopiée du gabarit */
    public function lock(int $randomSeed): void
    {
        $this->seed ??= $randomSeed;
        $this->shape = ($this->shapeTemplate?->toShape() ?? new SpiralGalaxyShape())->toArray();
    }

    public function getShape(): SpiralGalaxyShape
    {
        if (null === $this->shape) {
            throw new \LogicException('La demande de génération n\'a pas été figée (lock()).');
        }

        return SpiralGalaxyShape::fromArray($this->shape);
    }

    public function getStatus(): GenerationStatus
    {
        return $this->status;
    }

    public function start(\DateTimeImmutable $now): void
    {
        if (GenerationStatus::Pending !== $this->status) {
            throw new \LogicException('Seule une génération en attente peut démarrer.');
        }
        $this->status = GenerationStatus::Running;
        $this->startedAt = $now;
    }

    public function complete(Galaxy $galaxy, int $planetCount, \DateTimeImmutable $now): void
    {
        $this->status = GenerationStatus::Completed;
        $this->galaxy = $galaxy;
        $this->planetCount = $planetCount;
        $this->finishedAt = $now;
    }

    public function fail(string $error, \DateTimeImmutable $now): void
    {
        $this->status = GenerationStatus::Failed;
        $this->error = $error;
        $this->finishedAt = $now;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getGalaxy(): ?Galaxy
    {
        return $this->galaxy;
    }

    public function getPlanetCount(): ?int
    {
        return $this->planetCount;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function __toString(): string
    {
        return \sprintf('Génération #%d — %s', (int) $this->id, $this->name ?? 'galaxie sans nom');
    }
}
