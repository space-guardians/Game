<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Fleet\FleetAction;
use App\Enum\Market\PlayerQuestStatus;
use App\Model\Economy\Resources;
use App\Repository\PlayerQuestRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Contrat de quête d'escorte (§4.15) : le posteur fera partir sa flotte (le convoi) de sa position à l'heure dite vers
 * une destination ; d'autres joueurs y attachent leurs flottes d'escorte (`EscortAssignment`), qui voyagent avec elle
 * et combattent dans son camp. La récompense, prélevée à la publication, revient aux escortes si le convoi arrive sans
 * perte, au posteur sinon. Seuls le posteur et les escortes connaissent la destination et l'heure d'arrivée : les
 * autres ne voient que le départ. Audité (§5.6.3).
 */
#[ORM\Entity(repositoryClass: PlayerQuestRepository::class)]
#[ORM\Index(name: 'player_quest_status_idx', fields: ['status'])]
#[Auditable]
final class PlayerQuest implements \Stringable
{
    /** Escortes au plus par contrat (provisoire) */
    public const int MAX_ESCORTS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: PlayerQuestStatus::class)]
    private PlayerQuestStatus $status = PlayerQuestStatus::Open;

    #[ORM\Column]
    private float $rewardMetal;

    #[ORM\Column]
    private float $rewardCrystal;

    #[ORM\Column]
    private float $rewardDeuterium;

    /** Vaisseaux du convoi au départ : il réussit s'il les a tous à l'arrivée */
    #[ORM\Column(nullable: true)]
    private ?int $convoyShips = null;

    /** Arrivée prévue du convoi, connue au départ */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $arrivesAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** Réveil du départ */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScheduledEvent $departureEvent = null;

    /** @var Collection<int, EscortAssignment> */
    #[ORM\OneToMany(targetEntity: EscortAssignment::class, mappedBy: 'quest', cascade: ['persist'])]
    private Collection $escorts;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $poster,
        /** Flotte protégée ; nulle si elle a disparu */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Fleet $convoy,
        /** Départ, public */
        #[ORM\Embedded(columnPrefix: 'departure_')]
        private readonly SpaceLocation $departure,
        #[ORM\Column(length: 60)]
        private readonly string $departureLabel,
        #[ORM\Column]
        private readonly \DateTimeImmutable $departsAt,
        /** Destination, secrète pour les autres joueurs */
        #[ORM\Embedded(columnPrefix: 'destination_')]
        private readonly SpaceLocation $destination,
        #[ORM\Column(length: 60)]
        private readonly string $destinationLabel,
        #[ORM\Column(length: 20, enumType: FleetAction::class)]
        private readonly FleetAction $action,
        Resources $reward,
        /**
         * Cargaison chargée au départ sur la planète du convoi
         *
         * @var array{metal?: float, crystal?: float, deuterium?: float}
         */
        #[ORM\Column(type: 'json')]
        private readonly array $cargo,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->rewardMetal = $reward->metal;
        $this->rewardCrystal = $reward->crystal;
        $this->rewardDeuterium = $reward->deuterium;
        $this->escorts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPoster(): Empire
    {
        return $this->poster;
    }

    public function getConvoy(): ?Fleet
    {
        return $this->convoy;
    }

    /** Convoi anéanti : la flotte n'existe plus */
    public function forgetConvoy(): void
    {
        $this->convoy = null;
    }

    public function getDeparture(): SpaceLocation
    {
        return $this->departure;
    }

    public function getDepartureLabel(): string
    {
        return $this->departureLabel;
    }

    public function getDepartsAt(): \DateTimeImmutable
    {
        return $this->departsAt;
    }

    public function getDestination(): SpaceLocation
    {
        return $this->destination;
    }

    public function getDestinationLabel(): string
    {
        return $this->destinationLabel;
    }

    public function getAction(): FleetAction
    {
        return $this->action;
    }

    public function getReward(): Resources
    {
        return new Resources($this->rewardMetal, $this->rewardCrystal, $this->rewardDeuterium);
    }

    public function getCargo(): Resources
    {
        return new Resources((float) ($this->cargo['metal'] ?? 0), (float) ($this->cargo['crystal'] ?? 0), (float) ($this->cargo['deuterium'] ?? 0));
    }

    public function getStatus(): PlayerQuestStatus
    {
        return $this->status;
    }

    public function getConvoyShips(): ?int
    {
        return $this->convoyShips;
    }

    public function getArrivesAt(): ?\DateTimeImmutable
    {
        return $this->arrivesAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDepartureEvent(): ?ScheduledEvent
    {
        return $this->departureEvent;
    }

    public function attachDepartureEvent(ScheduledEvent $event): void
    {
        $this->departureEvent = $event;
    }

    /** @return Collection<int, EscortAssignment> */
    public function getEscorts(): Collection
    {
        return $this->escorts;
    }

    public function escortOf(Fleet $fleet): ?EscortAssignment
    {
        return $this->escorts->findFirst(static fn(int $key, EscortAssignment $escort): bool => $escort->getFleet() === $fleet);
    }

    /** Le posteur et les empires qui escortent connaissent la destination ; les autres non */
    public function isKnownTo(Empire $empire): bool
    {
        return $empire === $this->poster || $this->escorts->exists(static fn(int $key, EscortAssignment $escort): bool => $escort->getEmpire() === $empire);
    }

    public function join(EscortAssignment $escort): void
    {
        if (PlayerQuestStatus::Open !== $this->status) {
            throw new \LogicException('Ce contrat n’accepte plus d’escorte.');
        }
        $this->escorts->add($escort);
    }

    public function leave(EscortAssignment $escort): void
    {
        $this->escorts->removeElement($escort);
    }

    /** Départ : effectif du convoi retenu pour juger de l'arrivée « sans perte » */
    public function setOff(int $convoyShips): void
    {
        if (PlayerQuestStatus::Open !== $this->status) {
            throw new \LogicException('Seul un contrat ouvert part.');
        }
        $this->status = PlayerQuestStatus::Underway;
        $this->convoyShips = $convoyShips;
    }

    public function scheduleArrival(\DateTimeImmutable $arrivesAt): void
    {
        $this->arrivesAt = $arrivesAt;
    }

    public function close(PlayerQuestStatus $status, \DateTimeImmutable $at): void
    {
        if (!$status->isClosed() || $this->status->isClosed()) {
            throw new \LogicException('Ce contrat est déjà clos.');
        }
        $this->status = $status;
        $this->closedAt = $at;
    }

    public function __toString(): string
    {
        return \sprintf('Escorte n°%d (%s)', $this->id ?? 0, $this->poster->getName());
    }
}
