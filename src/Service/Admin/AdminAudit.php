<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Enum\Admin\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Journal des actions d'administration (§5.6.2) : actions métier qui ne se résument pas à une écriture d'entité,
 * ou qui s'exécutent hors de la requête de l'administrateur (génération en arrière-plan, sanction…).
 * Les créations, modifications et suppressions d'entités sont historisées par l'auditeur (§5.6.3).
 */
final readonly class AdminAudit
{
    /** Champs dont la valeur n'est jamais recopiée dans le journal */
    private const array SENSITIVE_FIELDS = ['password', 'plainPassword', 'totpSecret'];
    private const string MASK = '••••••';
    private const string SYSTEM_ACTOR = 'système';

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /**
     * Enregistre immédiatement une action.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes champ => [avant, après]
     * @param AdminUser|null                           $actor   auteur, par défaut le compte connecté
     */
    public function record(AuditAction $action, object $subject, array $changes = [], ?AdminUser $actor = null): AdminAuditLog
    {
        $entry = $this->entry($action, $subject, $changes, $actor);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /**
     * Prépare une entrée sans l'enregistrer.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    public function entry(AuditAction $action, object $subject, array $changes = [], ?AdminUser $actor = null): AdminAuditLog
    {
        $actor ??= $this->currentAdmin();
        $normalized = [];
        foreach ($changes as $field => [$before, $after]) {
            $normalized[$field] = \in_array($field, self::SENSITIVE_FIELDS, true)
                ? [null === $before ? null : self::MASK, null === $after ? null : self::MASK]
                : [$this->normalize($before), $this->normalize($after)];
        }

        return new AdminAuditLog(
            occurredAt: $this->clock->now(),
            actorId: $actor?->getId(),
            actorEmail: $actor?->getEmail() ?? self::SYSTEM_ACTOR,
            action: $action,
            subjectType: new \ReflectionClass($subject)->getShortName(),
            subjectId: $this->identifier($subject),
            subjectLabel: mb_substr($this->label($subject), 0, 255),
            changes: $normalized,
        );
    }

    /** Compte d'administration connecté, ou null (joueur, commande, tâche de fond) */
    public function currentAdmin(): ?AdminUser
    {
        $user = $this->security->getUser();

        return $user instanceof AdminUser ? $user : null;
    }

    /** Valeur enregistrable en JSON et lisible dans l'écran du journal */
    private function normalize(mixed $value): mixed
    {
        return match (true) {
            null === $value, \is_scalar($value) => $value,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            \is_object($value) => $this->label($value),
            default => json_encode($value, \JSON_THROW_ON_ERROR),
        };
    }

    private function label(object $subject): string
    {
        if ($subject instanceof \Stringable) {
            return (string) $subject;
        }
        $identifier = $this->identifier($subject);

        return new \ReflectionClass($subject)->getShortName() . (null === $identifier ? '' : ' #' . $identifier);
    }

    private function identifier(object $subject): ?string
    {
        if ($this->entityManager->getMetadataFactory()->isTransient($subject::class)) {
            return null;
        }
        $values = $this->entityManager->getClassMetadata($subject::class)->getIdentifierValues($subject);

        return [] === $values ? null : implode('-', array_map(strval(...), $values));
    }
}
