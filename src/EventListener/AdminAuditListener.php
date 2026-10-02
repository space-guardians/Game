<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Admin\AdminAudit;
use App\Admin\AuditAction;
use App\Entity\AdminAuditLog;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Enregistre dans le journal d'audit toute création, modification ou suppression d'entité faite par un compte
 * d'administration connecté (§5.6.2). Les écritures des joueurs, des commandes et des tâches de fond ne passent
 * pas par ici : celles qui relèvent de l'administration appellent AdminAudit::record().
 *
 * Les créations sont journalisées après l'écriture (postFlush), une fois leur identifiant connu ; les
 * suppressions avant (onFlush), tant que l'entité a encore ses valeurs.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AdminAuditListener implements ResetInterface
{
    /** @var list<array{AuditAction, object, array<string, array{0: mixed, 1: mixed}>}> */
    private array $pending = [];

    /** @var list<AdminAuditLog> */
    private array $deletions = [];

    public function __construct(
        private readonly AdminAudit $audit,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $actor = $this->audit->currentAdmin();
        if (null === $actor) {
            return;
        }

        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof AdminAuditLog) {
                $this->pending[] = [AuditAction::Create, $entity, []];
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            /** @var array<string, array{0: mixed, 1: mixed}> $changes */
            $changes = $unitOfWork->getEntityChangeSet($entity);
            if (!$entity instanceof AdminAuditLog && [] !== $changes) {
                $this->pending[] = [AuditAction::Update, $entity, $changes];
            }
        }
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof AdminAuditLog) {
                $before = array_map(static fn(mixed $value): array => [$value, null], $this->audit->snapshot($entity));
                $this->deletions[] = $this->audit->entry(AuditAction::Delete, $entity, $before, $actor);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending && [] === $this->deletions) {
            return;
        }

        $entries = $this->deletions;
        foreach ($this->pending as [$action, $entity, $changes]) {
            if (AuditAction::Create === $action) {
                $changes = array_map(static fn(mixed $value): array => [null, $value], $this->audit->snapshot($entity));
            }
            $entries[] = $this->audit->entry($action, $entity, $changes);
        }
        // Vidé avant le second flush, qui repasse par onFlush et postFlush
        $this->reset();

        $entityManager = $args->getObjectManager();
        foreach ($entries as $entry) {
            $entityManager->persist($entry);
        }
        $entityManager->flush();
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->deletions = [];
    }
}
