<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AlliancePactRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pacte de non-agression entre deux empires (§4.10.1) : proposé par l'un avec une durée (au plus un an), accepté par
 * l'autre. Une fois conclu, il court jusqu'à son terme : aucune résiliation, ni unilatérale ni mutuelle. Pendant sa
 * durée, les deux empires sont alliés. Une proposition non acceptée peut être refusée ou retirée. Audité (§5.6.3).
 */
#[ORM\Entity(repositoryClass: AlliancePactRepository::class)]
#[ORM\Index(name: 'alliance_pact_ends_idx', fields: ['endsAt'])]
#[Auditable]
final class AlliancePact implements \Stringable
{
    public const int MAX_DAYS = 365;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Conclusion du pacte ; null tant que ce n'est qu'une proposition */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $concludedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $proposer,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $partner,
        /** Durée négociée, en jours */
        #[ORM\Column]
        private readonly int $days,
        #[ORM\Column]
        private readonly \DateTimeImmutable $proposedAt,
    ) {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException(\sprintf('Un pacte dure de 1 à %d jours.', self::MAX_DAYS));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProposer(): Empire
    {
        return $this->proposer;
    }

    public function getPartner(): Empire
    {
        return $this->partner;
    }

    /** L'autre partie du pacte, vue par l'un des deux empires */
    public function counterpart(Empire $empire): Empire
    {
        return $this->proposer === $empire ? $this->partner : $this->proposer;
    }

    public function involves(Empire $empire): bool
    {
        return $this->proposer === $empire || $this->partner === $empire;
    }

    public function getDays(): int
    {
        return $this->days;
    }

    public function getProposedAt(): \DateTimeImmutable
    {
        return $this->proposedAt;
    }

    public function getConcludedAt(): ?\DateTimeImmutable
    {
        return $this->concludedAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isConcluded(): bool
    {
        return null !== $this->concludedAt;
    }

    public function isActiveAt(\DateTimeImmutable $at): bool
    {
        return null !== $this->concludedAt && null !== $this->endsAt && $this->concludedAt <= $at && $at < $this->endsAt;
    }

    public function conclude(\DateTimeImmutable $at): void
    {
        if ($this->isConcluded()) {
            throw new \LogicException('Ce pacte est déjà conclu.');
        }
        $this->concludedAt = $at;
        $this->endsAt = $at->modify(\sprintf('+%d days', $this->days));
    }

    public function __toString(): string
    {
        return \sprintf('Pacte %s – %s (%d j)', $this->proposer->getName(), $this->partner->getName(), $this->days);
    }
}
