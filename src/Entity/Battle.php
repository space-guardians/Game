<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Combat\BattleStatus;
use App\Enum\Combat\CombatSide;
use App\Repository\BattleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bataille programmée (§4.7) : dès qu'une attaque est lancée vers une position, elle devient une bataille à ce lieu,
 * résolue à l'heure d'arrivée du premier assaillant. Jusque-là, d'autres flottes peuvent la rejoindre ; à l'heure dite,
 * les assaillants présents affrontent les flottes hostiles sur place (moteur de combat). Non auditée : son déroulé
 * est enregistré ici (et dans le rapport de combat, #48).
 */
#[ORM\Entity(repositoryClass: BattleRepository::class)]
#[ORM\Index(name: 'battle_status_resolves_idx', fields: ['status', 'resolvesAt'])]
final class Battle implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: BattleStatus::class)]
    private BattleStatus $status = BattleStatus::Scheduled;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Camp vainqueur ; null pour un match nul ou une bataille annulée */
    #[ORM\Column(length: 20, nullable: true, enumType: CombatSide::class)]
    private ?CombatSide $winner = null;

    /** Rounds joués */
    #[ORM\Column]
    private int $rounds = 0;

    /**
     * Pertes par camp et par type de vaisseau, en attendant le rapport de combat détaillé (#48).
     *
     * @var array<string, array<string, int>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $losses = [];

    /** @var Collection<int, BattleParticipant> */
    #[ORM\OneToMany(targetEntity: BattleParticipant::class, mappedBy: 'battle', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $participants;

    public function __construct(
        #[ORM\Embedded(columnPrefix: 'location_')]
        private readonly SpaceLocation $location,
        #[ORM\Column(length: 60)]
        private readonly string $locationLabel,
        #[ORM\Column]
        private readonly \DateTimeImmutable $scheduledAt,
        /** Heure de résolution : arrivée du premier assaillant */
        #[ORM\Column]
        private readonly \DateTimeImmutable $resolvesAt,
    ) {
        $this->participants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocation(): SpaceLocation
    {
        return $this->location;
    }

    public function getLocationLabel(): string
    {
        return $this->locationLabel;
    }

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getResolvesAt(): \DateTimeImmutable
    {
        return $this->resolvesAt;
    }

    public function getStatus(): BattleStatus
    {
        return $this->status;
    }

    public function isScheduled(): bool
    {
        return BattleStatus::Scheduled === $this->status;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getWinner(): ?CombatSide
    {
        return $this->winner;
    }

    public function getRounds(): int
    {
        return $this->rounds;
    }

    /** @return array<string, array<string, int>> */
    public function getLosses(): array
    {
        return $this->losses;
    }

    /** @return Collection<int, BattleParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function participantFor(Fleet $fleet): ?BattleParticipant
    {
        return $this->participants->findFirst(static fn(int $key, BattleParticipant $participant): bool => $participant->getFleet() === $fleet);
    }

    public function join(Fleet $fleet, CombatSide $side, \DateTimeImmutable $at, int $speedPercent = 100): BattleParticipant
    {
        $participant = $this->participantFor($fleet);
        if (null === $participant) {
            $participant = new BattleParticipant($this, $fleet, $side, $at, $speedPercent);
            $this->participants->add($participant);
        }

        return $participant;
    }

    /**
     * @param array<string, array<string, int>> $losses par camp (valeur de CombatSide), puis par type de vaisseau
     */
    public function resolve(?CombatSide $winner, int $rounds, array $losses, \DateTimeImmutable $at): void
    {
        $this->status = BattleStatus::Resolved;
        $this->winner = $winner;
        $this->rounds = $rounds;
        $this->losses = $losses;
        $this->resolvedAt = $at;
    }

    public function cancel(\DateTimeImmutable $at): void
    {
        $this->status = BattleStatus::Cancelled;
        $this->resolvedAt = $at;
    }

    public function __toString(): string
    {
        return \sprintf('Bataille en %s (%s)', $this->locationLabel, $this->resolvesAt->format('d/m H:i'));
    }
}
