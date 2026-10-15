<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Account\EmpireKind;
use App\Enum\Account\StartingOrientation;
use App\Repository\EmpireRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Empire d'un joueur : un compte = un empire (§4.1). Il naît à l'inscription avec sa planète mère, placée selon
 * l'orientation choisie (§2.4). L'alliance arrivera avec les alliances (phase Social).
 *
 * @see §4.1 du cahier des charges
 */
#[ORM\Entity(repositoryClass: EmpireRepository::class)]
#[ORM\UniqueConstraint(name: 'empire_name_unique', fields: ['name'])]
#[Auditable]
final class Empire
{
    public const int NAME_MIN_LENGTH = 3;
    public const int NAME_MAX_LENGTH = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $score = 0;

    /** Planète affichée et pilotée par défaut ; la planète mère au départ */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Planet $activePlanet;

    /** Joueur, ou civilisation non-joueuse gérée par le moteur de jeu (§4.14) : sans compte, avec un profil */
    #[ORM\Column(length: 10, enumType: EmpireKind::class, options: ['default' => 'player'])]
    private EmpireKind $kind = EmpireKind::Player;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?NpcBehaviorProfile $npcProfile = null;

    /** Dernier raid lancé par une civilisation non-joueuse (§4.14) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $npcLastRaidAt = null;

    /** Dernier recalcul de la flotte d'une civilisation « boss » (§4.14) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $npcFleetRecalculatedAt = null;

    /** Début du mode vacances (§4.13) ; null hors vacances */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $vacationSince = null;

    /** Mise en sourdine du chat par la modération (§4.13), jusqu'à cette date */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $chatMutedUntil = null;

    /** @var Collection<int, Research> niveaux de recherche, valables pour toutes les planètes (§4.4) */
    #[ORM\OneToMany(targetEntity: Research::class, mappedBy: 'empire', cascade: ['persist'])]
    private Collection $researches;

    public function __construct(
        /** Compte du joueur ; null pour une civilisation non-joueuse */
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'CASCADE')]
        private readonly ?User $user,
        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        private string $name,
        #[ORM\Column(length: 20, enumType: StartingOrientation::class)]
        private readonly StartingOrientation $orientation,
        /** Une galaxie qui porte des planètes mères ne peut pas être supprimée (clé étrangère restrictive) */
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true)]
        private readonly Planet $homePlanet,
        #[ORM\Column]
        private readonly \DateTimeImmutable $foundedAt,
    ) {
        $this->name = self::normalizeName($name);
        $homePlanet->assignTo($this);
        $this->activePlanet = $homePlanet;
        $this->researches = new ArrayCollection();
    }

    /** Civilisation non-joueuse (§4.14) : sans compte, pilotée par le moteur de jeu selon son profil */
    public static function npc(string $name, Planet $homePlanet, NpcBehaviorProfile $profile, \DateTimeImmutable $foundedAt): self
    {
        $empire = new self(null, $name, StartingOrientation::Aggressive, $homePlanet, $foundedAt);
        $empire->kind = EmpireKind::Npc;
        $empire->npcProfile = $profile;

        return $empire;
    }

    /** Espaces superflus retirés ; la casse est conservée mais ne distingue pas deux empires */
    public static function normalizeName(string $name): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($name));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @throws \LogicException pour une civilisation non-joueuse, qui n'a pas de compte */
    public function getUser(): User
    {
        return $this->user ?? throw new \LogicException(\sprintf('La civilisation « %s » n’a pas de compte joueur.', $this->name));
    }

    public function getVacationSince(): ?\DateTimeImmutable
    {
        return $this->vacationSince;
    }

    public function isOnVacation(): bool
    {
        return null !== $this->vacationSince;
    }

    /** Consolider les ressources de ses planètes avant : la production change */
    public function startVacation(\DateTimeImmutable $at): void
    {
        $this->vacationSince ??= $at;
    }

    /** Consolider les ressources de ses planètes avant, pendant qu'il est encore en vacances */
    public function endVacation(): void
    {
        $this->vacationSince = null;
    }

    public function getChatMutedUntil(): ?\DateTimeImmutable
    {
        return $this->chatMutedUntil;
    }

    public function isChatMutedAt(\DateTimeImmutable $at): bool
    {
        return null !== $this->chatMutedUntil && $at < $this->chatMutedUntil;
    }

    public function muteChat(?\DateTimeImmutable $until): void
    {
        $this->chatMutedUntil = $until;
    }

    public function getKind(): EmpireKind
    {
        return $this->kind;
    }

    public function isNpc(): bool
    {
        return EmpireKind::Npc === $this->kind;
    }

    public function getNpcProfile(): ?NpcBehaviorProfile
    {
        return $this->npcProfile;
    }

    public function getNpcLastRaidAt(): ?\DateTimeImmutable
    {
        return $this->npcLastRaidAt;
    }

    public function getNpcFleetRecalculatedAt(): ?\DateTimeImmutable
    {
        return $this->npcFleetRecalculatedAt;
    }

    public function npcFleetRecalculated(\DateTimeImmutable $at): void
    {
        $this->npcFleetRecalculatedAt = $at;
    }

    public function npcRaided(\DateTimeImmutable $at): void
    {
        $this->npcLastRaidAt = $at;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOrientation(): StartingOrientation
    {
        return $this->orientation;
    }

    public function getHomePlanet(): Planet
    {
        return $this->homePlanet;
    }

    public function getActivePlanet(): Planet
    {
        return $this->activePlanet;
    }

    /** Planète pilotée par défaut (sélecteur de planète) : uniquement une planète de l'empire */
    public function switchTo(Planet $planet): void
    {
        if ($planet->getOwner() !== $this) {
            throw new \DomainException(\sprintf('La planète %s n\'appartient pas à l\'empire « %s ».', $planet, $this->name));
        }
        $this->activePlanet = $planet;
    }

    public function isHomePlanet(Planet $planet): bool
    {
        return $planet === $this->homePlanet;
    }

    /** @return Collection<int, Research> */
    public function getResearches(): Collection
    {
        return $this->researches;
    }

    public function researchLevel(Technology $technology): int
    {
        return $this->findResearch($technology)?->getLevel() ?? 0;
    }

    public function setResearchLevel(Technology $technology, int $level): void
    {
        $research = $this->findResearch($technology);
        if (null === $research) {
            $research = new Research($this, $technology);
            $this->researches->add($research);
        }
        $research->setLevel($level);
    }

    private function findResearch(Technology $technology): ?Research
    {
        return $this->researches->findFirst(static fn(int $key, Research $research): bool => $research->getTechnology() === $technology);
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getFoundedAt(): \DateTimeImmutable
    {
        return $this->foundedAt;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
