<?php

declare(strict_types=1);

namespace App\Enum\Admin;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Nature d'une action enregistrée dans le journal des actions d'administration (§5.6.2). Les créations,
 * modifications et suppressions de données n'y figurent pas : elles sont dans l'historique (§5.6.3).
 */
enum AuditAction: string implements TranslatableInterface
{
    case Generate = 'generate';
    case Sanction = 'sanction';
    case ResetTwoFactor = 'reset_two_factor';

    public function label(): string
    {
        return match ($this) {
            self::Generate => 'Génération',
            self::Sanction => 'Sanction',
            self::ResetTwoFactor => 'Réinitialisation de la double authentification',
        };
    }

    /** Libellé affiché par les filtres et listes d'EasyAdmin ; l'interface est en français */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
