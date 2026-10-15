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
    case Retry = 'retry';
    case Discard = 'discard';
    case Announce = 'announce';
    case NpcFound = 'npc_found';
    case NpcOrder = 'npc_order';

    public function label(): string
    {
        return match ($this) {
            self::Generate => 'Génération',
            self::Sanction => 'Sanction',
            self::ResetTwoFactor => 'Réinitialisation de la double authentification',
            self::Retry => 'Relance',
            self::Discard => 'Suppression d’un message',
            self::Announce => 'Annonce',
            self::NpcFound => 'Fondation d’une civilisation NPC',
            self::NpcOrder => 'Ordre à une civilisation NPC',
        };
    }

    /** Libellé affiché par les filtres et listes d'EasyAdmin ; l'interface est en français */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
