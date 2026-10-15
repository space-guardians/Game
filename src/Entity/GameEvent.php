<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Event\GameEventScope;
use App\Enum\Event\GameEventStatus;
use App\Repository\GameEventRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Événement de jeu (§4.16) : période, portée (univers, galaxie, zone) et effets en pourcentage (production, vitesse de
 * construction et de recherche, butin d'exploration). Créé et planifié dans le panneau ; démarré et arrêté par les
 * événements planifiés. Audité (§5.6.3).
 */
#[ORM\Entity(repositoryClass: GameEventRepository::class)]
#[ORM\Index(name: 'game_event_status_idx', fields: ['status'])]
#[Auditable]
class GameEvent implements \Stringable
{
    /** Bonus au plus par effet, en pourcentage */
    public const int MAX_BONUS = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Donnez un nom à l’événement.')]
    #[Assert\Length(max: 80)]
    private string $name = '';

    #[ORM\Column(length: 500)]
    #[Assert\Length(max: 500)]
    private string $description = '';

    #[ORM\Column]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 20, enumType: GameEventScope::class)]
    private GameEventScope $scope = GameEventScope::Universe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Galaxy $galaxy = null;

    /** Zone : centre (repère global) et rayon */
    #[ORM\Column(nullable: true)]
    private ?float $zoneX = null;

    #[ORM\Column(nullable: true)]
    private ?float $zoneY = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le rayon de la zone est positif.')]
    private ?float $zoneRadius = null;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: self::MAX_BONUS)]
    private int $productionBonus = 0;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: self::MAX_BONUS)]
    private int $constructionBonus = 0;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: self::MAX_BONUS)]
    private int $researchBonus = 0;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: self::MAX_BONUS)]
    private int $explorationBonus = 0;

    #[ORM\Column(length: 20, enumType: GameEventStatus::class)]
    private GameEventStatus $status = GameEventStatus::Scheduled;

    public function __construct(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt)
    {
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->endsAt <= $this->startsAt) {
            $context->buildViolation('La fin doit suivre le début.')->atPath('endsAt')->addViolation();
        }
        if (GameEventScope::Universe !== $this->scope && null === $this->galaxy) {
            $context->buildViolation('Choisissez la galaxie concernée.')->atPath('galaxy')->addViolation();
        }
        if (GameEventScope::Zone === $this->scope && (null === $this->zoneX || null === $this->zoneY || null === $this->zoneRadius)) {
            $context->buildViolation('Une zone a un centre (X, Y) et un rayon.')->atPath('zoneRadius')->addViolation();
        }
        if (0 === $this->productionBonus + $this->constructionBonus + $this->researchBonus + $this->explorationBonus) {
            $context->buildViolation('Un événement a au moins un effet.')->atPath('productionBonus')->addViolation();
        }
    }

    /** L'événement s'applique-t-il à cette planète ? */
    public function covers(Planet $planet): bool
    {
        $system = $planet->getSystem();

        return match ($this->scope) {
            GameEventScope::Universe => true,
            GameEventScope::Galaxy => $system->getGalaxy() === $this->galaxy,
            GameEventScope::Zone => $system->getGalaxy() === $this->galaxy
                && $system->getPosition()->distanceTo(new GlobalPosition((float) $this->zoneX, (float) $this->zoneY)) <= (float) $this->zoneRadius,
        };
    }

    public function isRunningAt(\DateTimeImmutable $at): bool
    {
        return GameEventStatus::Running === $this->status && $this->startsAt <= $at && $at < $this->endsAt;
    }

    public function start(): void
    {
        if (GameEventStatus::Scheduled === $this->status) {
            $this->status = GameEventStatus::Running;
        }
    }

    public function finish(): void
    {
        if (GameEventStatus::Running === $this->status) {
            $this->status = GameEventStatus::Finished;
        }
    }

    public function cancel(): void
    {
        if (\in_array($this->status, [GameEventStatus::Scheduled, GameEventStatus::Running], true)) {
            $this->status = GameEventStatus::Cancelled;
        }
    }

    /** Effets lisibles : « production +20 %, recherche +50 % » */
    public function effectsLabel(): string
    {
        $effects = [];
        foreach (['production' => $this->productionBonus, 'construction' => $this->constructionBonus, 'recherche' => $this->researchBonus, 'butin d’exploration' => $this->explorationBonus] as $label => $bonus) {
            if ($bonus > 0) {
                $effects[] = \sprintf('%s +%d %%', $label, $bonus);
            }
        }

        return implode(', ', $effects);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = (string) $description;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt;
    }

    public function getScope(): GameEventScope
    {
        return $this->scope;
    }

    public function setScope(GameEventScope $scope): void
    {
        $this->scope = $scope;
    }

    public function getGalaxy(): ?Galaxy
    {
        return $this->galaxy;
    }

    public function setGalaxy(?Galaxy $galaxy): void
    {
        $this->galaxy = $galaxy;
    }

    public function getZoneX(): ?float
    {
        return $this->zoneX;
    }

    public function setZoneX(?float $zoneX): void
    {
        $this->zoneX = $zoneX;
    }

    public function getZoneY(): ?float
    {
        return $this->zoneY;
    }

    public function setZoneY(?float $zoneY): void
    {
        $this->zoneY = $zoneY;
    }

    public function getZoneRadius(): ?float
    {
        return $this->zoneRadius;
    }

    public function setZoneRadius(?float $zoneRadius): void
    {
        $this->zoneRadius = $zoneRadius;
    }

    public function getProductionBonus(): int
    {
        return $this->productionBonus;
    }

    public function setProductionBonus(int $bonus): void
    {
        $this->productionBonus = $bonus;
    }

    public function getConstructionBonus(): int
    {
        return $this->constructionBonus;
    }

    public function setConstructionBonus(int $bonus): void
    {
        $this->constructionBonus = $bonus;
    }

    public function getResearchBonus(): int
    {
        return $this->researchBonus;
    }

    public function setResearchBonus(int $bonus): void
    {
        $this->researchBonus = $bonus;
    }

    public function getExplorationBonus(): int
    {
        return $this->explorationBonus;
    }

    public function setExplorationBonus(int $bonus): void
    {
        $this->explorationBonus = $bonus;
    }

    public function getStatus(): GameEventStatus
    {
        return $this->status;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
