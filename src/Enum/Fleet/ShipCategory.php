<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Catégorie d'un type de vaisseau (§4.5) : civil (transport, colonisation, recyclage, espionnage) ou militaire — un
 * type militaire appartient toujours à une classe de combat —, ou défense planétaire (§4.8) : construite au chantier
 * spatial mais immobile, elle ne rejoint jamais une flotte et ne défend que sa planète.
 */
enum ShipCategory: string implements TranslatableInterface
{
    case Civil = 'civil';
    case Military = 'military';
    case Defense = 'defense';

    public function label(): string
    {
        return match ($this) {
            self::Civil => 'Civil',
            self::Military => 'Militaire',
            self::Defense => 'Défense',
        };
    }

    /** Libellé affiché par les listes et filtres d'EasyAdmin ; l'interface est en français */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
