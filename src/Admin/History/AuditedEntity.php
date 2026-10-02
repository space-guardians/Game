<?php

declare(strict_types=1);

namespace App\Admin\History;

/**
 * Entité dont les modifications sont historisées (#[Auditable]).
 */
final readonly class AuditedEntity
{
    /**
     * @param class-string $class
     * @param string       $key        identifiant dans les URL du panneau : nom de la table de l'entité
     * @param string       $auditTable table où l'auditeur enregistre l'historique
     */
    public function __construct(
        public string $class,
        public string $key,
        public string $label,
        public string $auditTable,
    ) {}
}
