<?php

declare(strict_types=1);

namespace App\Enum\Alliance;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rôle dans une alliance (§4.10) : le fondateur gère tout (rôles, exclusions, dissolution), le diplomate recrute (et
 * négociera les pactes, #56), le membre participe.
 */
enum AllianceRole: string implements TranslatableInterface
{
    case Founder = 'founder';
    case Diplomat = 'diplomat';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Founder => 'Fondateur',
            self::Diplomat => 'Diplomate',
            self::Member => 'Membre',
        };
    }

    /** Peut inviter de nouveaux membres */
    public function canRecruit(): bool
    {
        return self::Member !== $this;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
