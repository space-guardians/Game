<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerIgnoreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Joueur ignoré (§4.12.4) : l'empire qui ignore ne reçoit plus les messages de chat ni de messagerie de l'empire
 * ignoré, qui ne peut plus l'ajouter à un groupe ni à une conversation. Non auditée : préférence personnelle, comme
 * la messagerie.
 */
#[ORM\Entity(repositoryClass: PlayerIgnoreRepository::class)]
#[ORM\UniqueConstraint(name: 'player_ignore_unique', fields: ['empire', 'ignored'])]
#[ORM\Index(name: 'player_ignore_ignored_idx', fields: ['ignored'])]
final class PlayerIgnore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** Empire qui ignore */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $ignored,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if ($empire === $ignored) {
            throw new \InvalidArgumentException('Un empire ne s’ignore pas lui-même.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getIgnored(): Empire
    {
        return $this->ignored;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
