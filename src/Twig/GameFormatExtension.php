<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/**
 * Formats de l'interface de jeu (charte §3) : durées estimées « 2 h 14 min », « 3 min 05 s », « 45 s ».
 */
final class GameFormatExtension
{
    #[AsTwigFilter('duration')]
    public function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return match (true) {
            $days > 0 => \sprintf('%d j %d h', $days, $hours),
            $hours > 0 => \sprintf('%d h %02d min', $hours, $minutes),
            $minutes > 0 => \sprintf('%d min %02d s', $minutes, $rest),
            default => \sprintf('%d s', $rest),
        };
    }
}
