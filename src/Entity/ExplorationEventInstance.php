<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Exploration\ExplorationEventStatus;
use App\Repository\ExplorationEventInstanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement d'exploration vécu par une flotte (§4.6.4) : créé à son arrivée en exploration (ou en suite d'une quête
 * précédente), il se résout aussitôt par tirage, ou attend le choix du joueur jusqu'à son échéance. Titre et textes
 * sont recopiés du gabarit, qui peut changer ou disparaître ensuite. Non audité : le dénouement est enregistré ici.
 */
#[ORM\Entity(repositoryClass: ExplorationEventInstanceRepository::class)]
#[ORM\Index(name: 'exploration_event_empire_idx', fields: ['empire', 'createdAt'])]
final class ExplorationEventInstance implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ExplorationEventStatus::class)]
    private ExplorationEventStatus $status;

    #[ORM\Column(length: 80)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $text;

    /** Issue choisie ou tirée */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?QuestOutcome $outcome = null;

    /** Libellé de l'issue, dénouement et effets, tels que vécus */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $outcomeLabel = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $report = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?QuestTemplate $template,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        /** Flotte exploratrice ; null si elle a disparu depuis */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Fleet $fleet,
        /** Lieu de l'événement */
        #[ORM\Embedded(columnPrefix: 'location_')]
        private readonly SpaceLocation $location,
        #[ORM\Column(length: 60)]
        private readonly string $locationLabel,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
        /** Vitesse de la mission, pour reprendre les ordres restants une fois l'événement réglé */
        #[ORM\Column]
        private readonly int $speedPercent = 100,
        /** Quête précédente de la chaîne */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private readonly ?self $previous = null,
    ) {
        \assert(null !== $template);
        $this->title = $template->getName();
        $this->text = $template->getText();
        $this->status = $template->isPlayerChoice() ? ExplorationEventStatus::AwaitingChoice : ExplorationEventStatus::Resolved;
        if ($template->isPlayerChoice()) {
            $this->expiresAt = $createdAt->modify(\sprintf('+%d minutes', $template->getResponseMinutes()));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ?QuestTemplate
    {
        return $this->template;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getFleet(): ?Fleet
    {
        return $this->fleet;
    }

    /** La flotte disparaît (détruite) : l'événement reste dans l'historique */
    public function releaseFleet(): void
    {
        $this->fleet = null;
    }

    public function getLocation(): SpaceLocation
    {
        return $this->location;
    }

    public function getLocationLabel(): string
    {
        return $this->locationLabel;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSpeedPercent(): int
    {
        return $this->speedPercent;
    }

    public function getPrevious(): ?self
    {
        return $this->previous;
    }

    public function getStatus(): ExplorationEventStatus
    {
        return $this->status;
    }

    public function isAwaitingChoice(): bool
    {
        return ExplorationEventStatus::AwaitingChoice === $this->status;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getOutcome(): ?QuestOutcome
    {
        return $this->outcome;
    }

    public function getOutcomeLabel(): ?string
    {
        return $this->outcomeLabel;
    }

    public function getReport(): ?string
    {
        return $this->report;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolve(QuestOutcome $outcome, string $report, \DateTimeImmutable $at): void
    {
        $this->status = ExplorationEventStatus::Resolved;
        $this->outcome = $outcome;
        $this->outcomeLabel = mb_substr($outcome->getLabel(), 0, 80);
        $this->report = $report;
        $this->resolvedAt = $at;
    }

    public function expire(\DateTimeImmutable $at): void
    {
        $this->status = ExplorationEventStatus::Expired;
        $this->report = 'Sans réponse à temps, l’occasion est passée.';
        $this->resolvedAt = $at;
    }

    public function __toString(): string
    {
        return \sprintf('%s (%s)', $this->title, $this->locationLabel);
    }
}
