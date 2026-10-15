<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Empire;
use App\Enum\Admin\AuditAction;
use App\Service\Messaging\SystemMessenger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Sanctions de la modération (§4.13, §5.6.1) : avertissement (message du jeu), mise en sourdine du chat pour quelques
 * heures, bannissement temporaire ou définitif, levée. Chacune est notée au journal des actions et le joueur en est
 * informé par un message du jeu.
 */
final readonly class Moderation
{
    public function __construct(
        private SystemMessenger $messenger,
        private AdminAudit $audit,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    public function warn(Empire $empire, string $message): void
    {
        $message = trim($message);
        if ('' === $message) {
            throw new \DomainException('Rédigez l’avertissement.');
        }
        $this->messenger->toEmpire($empire, 'Avertissement de la modération', $message);
        $this->audit->record(AuditAction::Sanction, $empire, ['avertissement' => [null, mb_substr($message, 0, 255)]]);
    }

    public function muteChat(Empire $empire, int $hours): void
    {
        if ($hours < 1 || $hours > 24 * 30) {
            throw new \DomainException('La sourdine dure de 1 heure à 30 jours.');
        }
        $until = $this->clock->now()->modify(\sprintf('+%d hours', $hours));
        $empire->muteChat($until);
        $this->entityManager->flush();
        $this->messenger->toEmpire($empire, 'Chat en sourdine', \sprintf('La modération vous a réduit au silence dans le chat jusqu’au %s.', $until->format('d/m/Y à H:i')));
        $this->audit->record(AuditAction::Sanction, $empire, ['sourdine du chat' => [null, $until->format('d/m/Y H:i')]]);
    }

    /** Bannissement pour un nombre de jours, ou définitif (null) */
    public function ban(Empire $empire, ?int $days, string $reason): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \DomainException('Indiquez le motif du bannissement.');
        }
        if (null !== $days && ($days < 1 || $days > 365)) {
            throw new \DomainException('Un bannissement temporaire dure de 1 à 365 jours.');
        }
        $now = $this->clock->now();
        $until = null === $days ? null : $now->modify(\sprintf('+%d days', $days));
        $empire->getUser()->ban($now, $until, $reason);
        $this->entityManager->flush();
        $this->audit->record(AuditAction::Sanction, $empire, ['bannissement' => [null, null === $until ? 'définitif' : 'jusqu’au ' . $until->format('d/m/Y H:i')], 'motif' => [null, mb_substr($reason, 0, 255)]]);
    }

    public function liftBan(Empire $empire): void
    {
        $empire->getUser()->liftBan();
        $this->entityManager->flush();
        $this->audit->record(AuditAction::Sanction, $empire, ['bannissement' => ['en vigueur', 'levé']]);
    }
}
