<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Notification\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification d'un empire (§4.12.3) : alerte courte sur un événement de jeu, avec lien vers l'écran concerné ;
 * historique consultable, badge des non lues. Distincte de la messagerie. Non auditée (volumineuse, sans enjeu).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(name: 'notification_empire_idx', fields: ['empire', 'createdAt'])]
class Notification
{
    public const int TEXT_MAX_LENGTH = 160;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Empire $empire,
        #[ORM\Column(length: 20, enumType: NotificationType::class)]
        private NotificationType $type,
        #[ORM\Column(length: self::TEXT_MAX_LENGTH)]
        private string $text,
        /** Écran concerné (chemin de l'application) */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $link,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->text = mb_substr($text, 0, self::TEXT_MAX_LENGTH);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }
}
