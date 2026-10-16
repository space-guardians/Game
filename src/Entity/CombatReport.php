<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Combat\CombatSide;
use App\Model\Economy\Resources;
use App\Repository\CombatReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rapport de combat (§4.7) : déroulé détaillé d'une bataille résolue — angle d'attaque subi par chaque camp, chaque
 * groupe de vaisseaux (flotte, type, case de formation, effectif, pertes, dégâts infligés et reçus), chaque round.
 * Les données sont figées à la résolution (noms recopiés). Il est envoyé à tous les empires engagés dans la bataille
 * (participants, et propriétaire de la planète défendue) ; l'envoi à leurs alliances viendra avec elles (#56). Non
 * audité : document de jeu figé.
 */
#[ORM\Entity(repositoryClass: CombatReportRepository::class)]
final class CombatReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, string>                                                                                                                                                                       $angles par camp : valeur d'AttackAngle
     * @param list<array{side: string, fleet: string, empire: string, type: string, class: string|null, row: string, column: string, count: int, lost: int, dealt: float, received: float}> $groups
     * @param list<array{number: int, shots: array<string, int>, hits: array<string, int>, absorbed: array<string, float>, damage: array<string, float>, losses: int}>                                 $rounds
     * @param array{metal?: float, crystal?: float}                                                                                                                                                    $debris
     */
    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private readonly Battle $battle,
        #[ORM\Column]
        private readonly \DateTimeImmutable $foughtAt,
        #[ORM\Column(length: 80)]
        private readonly string $locationLabel,
        #[ORM\Column(length: 20, nullable: true, enumType: CombatSide::class)]
        private readonly ?CombatSide $winner,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $angles,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $groups,
        #[ORM\Column(type: Types::JSON)]
        private readonly array $rounds,
        /** Champ de débris formé (métal, cristal) */
        #[ORM\Column(type: Types::JSON)]
        private readonly array $debris = [],
    ) {}

    /**
     * Butin emporté par les assaillants (métal, cristal, deutérium).
     *
     * @var array{metal?: float, crystal?: float, deuterium?: float}
     */
    #[ORM\Column(type: Types::JSON)]
    private array $plunder = [];

    /** @return array{metal?: float, crystal?: float, deuterium?: float} */
    public function getPlunder(): array
    {
        return $this->plunder;
    }

    public function recordPlunder(Resources $plunder): void
    {
        $this->plunder = $plunder->metal + $plunder->crystal + $plunder->deuterium > 0
            ? ['metal' => round($plunder->metal), 'crystal' => round($plunder->crystal), 'deuterium' => round($plunder->deuterium)]
            : [];
    }

    /**
     * Propriétaire de la planète défendue au moment du combat : il lit le rapport, côté défenseur, même si seules ses
     * défenses ont combattu (aucune de ses flottes parmi les participants)
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Empire $defendedBy = null;

    public function getDefendedBy(): ?Empire
    {
        return $this->defendedBy;
    }

    public function recordDefender(Empire $owner): void
    {
        $this->defendedBy = $owner;
    }

    /** @return array{metal?: float, crystal?: float} */
    public function getDebris(): array
    {
        return $this->debris;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBattle(): Battle
    {
        return $this->battle;
    }

    public function getFoughtAt(): \DateTimeImmutable
    {
        return $this->foughtAt;
    }

    public function getLocationLabel(): string
    {
        return $this->locationLabel;
    }

    public function getWinner(): ?CombatSide
    {
        return $this->winner;
    }

    /** @return array<string, string> */
    public function getAngles(): array
    {
        return $this->angles;
    }

    /** @return list<array{side: string, fleet: string, empire: string, type: string, class: string|null, row: string, column: string, count: int, lost: int, dealt: float, received: float}> */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /** @return list<array{number: int, shots: array<string, int>, hits: array<string, int>, absorbed: array<string, float>, damage: array<string, float>, losses: int}> */
    public function getRounds(): array
    {
        return $this->rounds;
    }

    /**
     * Camp du premier de ces empires engagé dans la bataille (le lecteur, puis ses alliés qui lui partagent leurs
     * rapports) ; null si aucun n'y était.
     *
     * @param list<Empire> $empires
     */
    public function sideOfAny(array $empires): ?CombatSide
    {
        foreach ($empires as $empire) {
            $side = $this->sideOf($empire);
            if (null !== $side) {
                return $side;
            }
        }

        return null;
    }

    /** Camp d'un empire dans la bataille (flotte engagée ou planète défendue) ; null s'il n'y était pas */
    public function sideOf(Empire $empire): ?CombatSide
    {
        foreach ($this->battle->getParticipants() as $participant) {
            if ($participant->getEmpire() === $empire) {
                return $participant->getSide();
            }
        }

        return $empire === $this->defendedBy ? CombatSide::Defender : null;
    }

    /**
     * Groupes d'un camp.
     *
     * @return list<array{side: string, fleet: string, empire: string, type: string, class: string|null, row: string, column: string, count: int, lost: int, dealt: float, received: float}>
     */
    public function groupsOf(CombatSide $side): array
    {
        return array_values(array_filter($this->groups, static fn(array $group): bool => $side->value === $group['side']));
    }
}
