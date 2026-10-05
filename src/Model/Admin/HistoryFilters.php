<?php

declare(strict_types=1);

namespace App\Model\Admin;

use App\Enum\Admin\AuditOrigin;
use DH\Auditor\Model\TransactionType;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Filtres de l'historique d'une entité, lus dans la requête (paramètres vides ou invalides ignorés).
 */
final readonly class HistoryFilters
{
    public function __construct(
        public ?TransactionType $type = null,
        public ?AuditOrigin $origin = null,
        /** Partie de l'identifiant de connexion de l'auteur (e-mail) */
        public ?string $author = null,
        public ?string $objectId = null,
        public ?\DateTimeImmutable $from = null,
        /** Inclus : jusqu'à la fin de cette journée */
        public ?\DateTimeImmutable $to = null,
    ) {}

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        return new self(
            type: TransactionType::tryFrom($query->getString('type')),
            origin: AuditOrigin::tryFrom($query->getString('origine')),
            author: self::text($query->getString('auteur')),
            objectId: self::text($query->getString('objet')),
            from: self::date($query->getString('du')),
            to: self::date($query->getString('au')),
        );
    }

    /**
     * Paramètres d'URL équivalents, pour la pagination.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'type' => $this->type?->value,
            'origine' => $this->origin?->value,
            'auteur' => $this->author,
            'objet' => $this->objectId,
            'du' => $this->from?->format('Y-m-d'),
            'au' => $this->to?->format('Y-m-d'),
        ], static fn(?string $value): bool => null !== $value);
    }

    private static function text(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return false === $date ? null : $date;
    }
}
