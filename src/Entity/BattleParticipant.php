<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Combat\CombatSide;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Flotte engagée dans une bataille (§4.7), dans un camp : assaillante (partie l'attaquer) ou défenseuse (présente sur
 * place à la résolution). Le nom de la flotte et son empire restent, même si la flotte disparaît.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'battle_participant_fleet_unique', fields: ['battle', 'fleet'])]
final class BattleParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private readonly Empire $empire;

    #[ORM\Column(length: Fleet::NAME_MAX_LENGTH)]
    private readonly string $fleetName;

    /**
     * Vaisseaux perdus, par type, une fois la bataille résolue.
     *
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $losses = [];

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'participants')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Battle $battle,
        /** null si la flotte a disparu depuis (détruite, dissoute) */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Fleet $fleet,
        #[ORM\Column(length: 20, enumType: CombatSide::class)]
        private readonly CombatSide $side,
        #[ORM\Column]
        private readonly \DateTimeImmutable $joinedAt,
        /** Vitesse de la mission, pour reprendre les ordres restants après la bataille */
        #[ORM\Column]
        private readonly int $speedPercent = 100,
    ) {
        \assert(null !== $fleet);
        $this->empire = $fleet->getEmpire();
        $this->fleetName = $fleet->getName();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBattle(): Battle
    {
        return $this->battle;
    }

    public function getFleet(): ?Fleet
    {
        return $this->fleet;
    }

    /** La flotte disparaît (détruite au combat) : sa participation reste */
    public function releaseFleet(): void
    {
        $this->fleet = null;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getFleetName(): string
    {
        return $this->fleetName;
    }

    public function getSide(): CombatSide
    {
        return $this->side;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getSpeedPercent(): int
    {
        return $this->speedPercent;
    }

    /** @return array<string, int> */
    public function getLosses(): array
    {
        return $this->losses;
    }

    /** @param array<string, int> $losses */
    public function recordLosses(array $losses): void
    {
        $this->losses = $losses;
    }
}
