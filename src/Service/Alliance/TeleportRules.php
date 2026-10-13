<?php

declare(strict_types=1);

namespace App\Service\Alliance;

/**
 * Règles des portes de téléportation (§4.10), pures. Valeurs provisoires (§7).
 */
final readonly class TeleportRules
{
    /** Transit entre deux portes de niveau 1 */
    public const int BASE_TRANSIT_SECONDS = 1800;

    public const int MIN_TRANSIT_SECONDS = 60;

    /** Délai de transit : la porte la moins développée des deux fixe le rythme, chaque niveau le divise d'autant */
    public function transitSeconds(int $entryLevel, int $exitLevel): int
    {
        $level = max(1, min($entryLevel, $exitLevel));

        return max(self::MIN_TRANSIT_SECONDS, intdiv(self::BASE_TRANSIT_SECONDS, $level));
    }
}
