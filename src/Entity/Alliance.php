<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Alliance\AllianceRole;
use App\Repository\AllianceRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alliance d'empires (§4.10) : nom et tag uniques, membres avec leur rôle. Les membres d'une même alliance sont alliés
 * entre eux (§4.10.1) : ils ne peuvent pas s'attaquer, se renforcent et défendent ensemble. Auditée (§5.6.3).
 */
#[ORM\Entity(repositoryClass: AllianceRepository::class)]
#[ORM\UniqueConstraint(name: 'alliance_name_unique', fields: ['name'])]
#[ORM\UniqueConstraint(name: 'alliance_tag_unique', fields: ['tag'])]
#[Auditable]
final class Alliance implements \Stringable
{
    public const int NAME_MIN_LENGTH = 3;

    public const int NAME_MAX_LENGTH = 30;

    public const int TAG_MIN_LENGTH = 2;

    public const int TAG_MAX_LENGTH = 5;

    public const int DESCRIPTION_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Paramètre : les rapports de combat des membres sont envoyés à toute l'alliance (§4.7) */
    #[ORM\Column]
    private bool $shareCombatReports = false;

    /** @var Collection<int, AllianceMember> */
    #[ORM\OneToMany(targetEntity: AllianceMember::class, mappedBy: 'alliance', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $members;

    public function __construct(
        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        private string $name,
        #[ORM\Column(length: self::TAG_MAX_LENGTH)]
        private string $tag,
        #[ORM\Column]
        private readonly \DateTimeImmutable $foundedAt,
    ) {
        $this->members = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getFoundedAt(): \DateTimeImmutable
    {
        return $this->foundedAt;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;
    }

    public function sharesCombatReports(): bool
    {
        return $this->shareCombatReports;
    }

    public function setShareCombatReports(bool $share): void
    {
        $this->shareCombatReports = $share;
    }

    /** @return Collection<int, AllianceMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function memberOf(Empire $empire): ?AllianceMember
    {
        return $this->members->findFirst(static fn(int $key, AllianceMember $member): bool => $member->getEmpire() === $empire);
    }

    public function enrol(Empire $empire, AllianceRole $role, \DateTimeImmutable $at): AllianceMember
    {
        $member = new AllianceMember($this, $empire, $role, $at);
        $this->members->add($member);

        return $member;
    }

    public function remove(AllianceMember $member): void
    {
        $this->members->removeElement($member);
    }

    public function getFounder(): ?AllianceMember
    {
        return $this->members->findFirst(static fn(int $key, AllianceMember $member): bool => AllianceRole::Founder === $member->getRole());
    }

    public function __toString(): string
    {
        return \sprintf('[%s] %s', $this->tag, $this->name);
    }
}
