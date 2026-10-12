<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Alliance\AllianceRole;
use App\Repository\AllianceMemberRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Appartenance d'un empire à une alliance (§4.10), avec son rôle. Un empire appartient au plus à une alliance.
 */
#[ORM\Entity(repositoryClass: AllianceMemberRepository::class)]
#[ORM\UniqueConstraint(name: 'alliance_member_empire_unique', fields: ['empire'])]
#[Auditable]
final class AllianceMember implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'members')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Alliance $alliance,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\Column(length: 20, enumType: AllianceRole::class)]
        private AllianceRole $role,
        #[ORM\Column]
        private readonly \DateTimeImmutable $joinedAt,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlliance(): Alliance
    {
        return $this->alliance;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getRole(): AllianceRole
    {
        return $this->role;
    }

    public function setRole(AllianceRole $role): void
    {
        $this->role = $role;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function __toString(): string
    {
        return \sprintf('%s (%s, %s)', $this->empire->getName(), $this->role->label(), $this->alliance);
    }
}
