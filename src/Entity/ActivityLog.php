<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FairPlay\ActivityAction;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entrée du journal d'activité d'un joueur (§4.13) : action, empreinte de l'adresse IP (HMAC, jamais l'adresse en
 * clair) et date. Sert à repérer les comptes multiples et à limiter les inscriptions par adresse. Non auditée.
 */
#[ORM\Entity(repositoryClass: ActivityLogRepository::class)]
#[ORM\Index(name: 'activity_log_user_idx', fields: ['user', 'createdAt'])]
#[ORM\Index(name: 'activity_log_ip_idx', fields: ['ipHash', 'action'])]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 20, enumType: ActivityAction::class)]
        private ActivityAction $action,
        /** Empreinte HMAC-SHA256 de l'adresse IP ; null hors requête HTTP */
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $ipHash,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAction(): ActivityAction
    {
        return $this->action;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
