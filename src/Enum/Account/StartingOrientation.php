<?php

declare(strict_types=1);

namespace App\Enum\Account;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Orientation de jeu choisie à l'inscription : elle ne décide que de la position de la planète mère.
 *
 * @see §2.4 du cahier des charges
 */
enum StartingOrientation: string implements TranslatableInterface
{
    case Aggressive = 'aggressive';
    case Producer = 'producer';

    public function label(): string
    {
        return match ($this) {
            self::Aggressive => 'Agressif',
            self::Producer => 'Producteur',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Aggressive => 'Départ près du centre de la galaxie, là où les empires sont nombreux : plus de contacts, plus de risques.',
            self::Producer => 'Départ en périphérie, dans une zone peu peuplée : un développement économique plus tranquille.',
        };
    }

    /** Libellé affiché par les formulaires et listes ; l'interface est en français */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
