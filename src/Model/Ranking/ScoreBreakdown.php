<?php

declare(strict_types=1);

namespace App\Model\Ranking;

/**
 * Points d'un empire par catégorie (§4.11) et score global, somme pondérée des catégories.
 */
final readonly class ScoreBreakdown
{
    public function __construct(
        public int $economy,
        public int $research,
        public int $military,
        public int $exploration,
        public int $total,
    ) {}
}
