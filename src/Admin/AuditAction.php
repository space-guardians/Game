<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Nature d'une action d'administration enregistrée dans le journal d'audit (§5.6.2).
 */
enum AuditAction: string implements TranslatableInterface
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Generate = 'generate';
    case Sanction = 'sanction';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Création',
            self::Update => 'Modification',
            self::Delete => 'Suppression',
            self::Generate => 'Génération',
            self::Sanction => 'Sanction',
        };
    }

    /** Libellé affiché par les filtres et listes d'EasyAdmin ; l'interface est en français */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
