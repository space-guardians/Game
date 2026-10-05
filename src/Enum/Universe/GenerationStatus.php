<?php

declare(strict_types=1);

namespace App\Enum\Universe;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Avancement d'une génération de galaxie lancée depuis le panneau d'administration (§5.6.1).
 */
enum GenerationStatus: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Running => 'En cours',
            self::Completed => 'Terminée',
            self::Failed => 'Échec',
        };
    }

    /** Style du badge dans le panneau (EasyAdmin) */
    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Running => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function isFinished(): bool
    {
        return self::Completed === $this || self::Failed === $this;
    }

    /** Libellé affiché par les listes et filtres d'EasyAdmin ; l'interface est en français */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
