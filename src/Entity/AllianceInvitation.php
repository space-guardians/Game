<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Invitation à rejoindre une alliance (§4.10), adressée à un empire par un recruteur (fondateur ou diplomate) ; elle
 * disparaît une fois acceptée ou refusée. Non auditée : seule l'appartenance qui en résulte l'est.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'alliance_invitation_unique', fields: ['alliance', 'empire'])]
final class AllianceInvitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Alliance $alliance,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private readonly ?Empire $invitedBy,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
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

    public function getInvitedBy(): ?Empire
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
