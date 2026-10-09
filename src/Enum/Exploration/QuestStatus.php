<?php

declare(strict_types=1);

namespace App\Enum\Exploration;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle de vie d'un gabarit de quête (§5.6.1) : un brouillon se prépare et se teste sans apparaître en jeu ; seule
 * une quête publiée apparaît en exploration ou s'enchaîne ; une quête archivée est retirée du jeu mais conservée.
 */
enum QuestStatus: string implements TranslatableInterface
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'Publiée',
            self::Archived => 'Archivée',
        };
    }

    /** Classe de badge du panneau (Bootstrap) */
    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Published => 'success',
            self::Archived => 'secondary',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
