<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Catégorie d'un type de vaisseau (§4.5) : civil (transport, colonisation, recyclage, espionnage) ou militaire.
 * Un type militaire appartient toujours à une classe de combat.
 */
enum ShipCategory: string implements TranslatableInterface
{
    case Civil = 'civil';
    case Military = 'military';

    public function label(): string
    {
        return match ($this) {
            self::Civil => 'Civil',
            self::Military => 'Militaire',
        };
    }

    /** Libellé affiché par les listes et filtres d'EasyAdmin ; l'interface est en français */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
