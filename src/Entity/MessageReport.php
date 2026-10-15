<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Moderation\ReportSource;
use App\Enum\Moderation\ReportStatus;
use App\Repository\MessageReportRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signalement d'un message par un joueur (§4.13) : le message visé et un extrait de son contexte, figés au moment du
 * signalement (le chat n'étant pas persistant, l'extrait vient de son tampon), le motif, puis le traitement par la
 * modération. Audité (§5.6.3).
 */
#[ORM\Entity(repositoryClass: MessageReportRepository::class)]
#[ORM\Index(name: 'message_report_status_idx', fields: ['status', 'createdAt'])]
#[Auditable]
class MessageReport
{
    public const int REASON_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ReportStatus::class)]
    private ReportStatus $status = ReportStatus::Open;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $handledAt = null;

    /** Modérateur qui a traité le signalement (adresse du compte d'administration) */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $handledBy = null;

    /**
     * @param list<array{author: string, body: string, sentAt: string}> $excerpt contexte, le message signalé en dernier
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Empire $reporter,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Empire $reported,
        #[ORM\Column(length: 20, enumType: ReportSource::class)]
        private ReportSource $source,
        /** Message signalé, tel qu'il a été lu */
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(type: 'json')]
        private array $excerpt,
        #[ORM\Column(length: self::REASON_MAX_LENGTH)]
        private string $reason,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->reason = mb_substr(trim($reason), 0, self::REASON_MAX_LENGTH);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReporter(): Empire
    {
        return $this->reporter;
    }

    public function getReported(): Empire
    {
        return $this->reported;
    }

    public function getSource(): ReportSource
    {
        return $this->source;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return list<array{author: string, body: string, sentAt: string}> */
    public function getExcerpt(): array
    {
        return $this->excerpt;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function getHandledAt(): ?\DateTimeImmutable
    {
        return $this->handledAt;
    }

    public function getHandledBy(): ?string
    {
        return $this->handledBy;
    }

    public function handle(ReportStatus $status, string $moderator, \DateTimeImmutable $at): void
    {
        if (ReportStatus::Open === $status) {
            throw new \LogicException('Un signalement se traite ou se classe.');
        }
        $this->status = $status;
        $this->handledBy = $moderator;
        $this->handledAt = $at;
    }

    /** Résumé lisible du contexte, pour le panneau */
    public function excerptText(): string
    {
        return implode("\n", array_map(static fn(array $line): string => \sprintf('[%s] %s : %s', $line['sentAt'], $line['author'], $line['body']), $this->excerpt));
    }
}
