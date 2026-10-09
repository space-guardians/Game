<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassMatchupRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Case de la matrice des classes (contenu de jeu, §4.7) : multiplicateur des dégâts infligés par un vaisseau de la
 * classe attaquante à un vaisseau de la classe visée (« pierre-feuille-ciseaux »). Seules les paires qui s'écartent
 * de ×1 sont enregistrées ; une paire absente est neutre.
 */
#[ORM\Entity(repositoryClass: ClassMatchupRepository::class)]
#[ORM\UniqueConstraint(name: 'class_matchup_pair_unique', fields: ['attacker', 'defender'])]
#[Auditable]
final class ClassMatchup implements \Stringable
{
    /** Bornes d'un multiplicateur (contrainte CHECK posée par la migration) */
    public const float MIN = 0.1;

    public const float MAX = 10.0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly ShipClass $attacker,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly ShipClass $defender,
        #[ORM\Column]
        private float $multiplier,
    ) {
        $this->setMultiplier($multiplier);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttacker(): ShipClass
    {
        return $this->attacker;
    }

    public function getDefender(): ShipClass
    {
        return $this->defender;
    }

    public function getMultiplier(): float
    {
        return $this->multiplier;
    }

    public function setMultiplier(float $multiplier): void
    {
        if ($multiplier < self::MIN || $multiplier > self::MAX) {
            throw new \InvalidArgumentException(\sprintf('Un multiplicateur va de %s à %s.', self::MIN, self::MAX));
        }
        $this->multiplier = $multiplier;
    }

    public function __toString(): string
    {
        return \sprintf('%s → %s : ×%s', $this->attacker->getName(), $this->defender->getName(), $this->multiplier);
    }
}
