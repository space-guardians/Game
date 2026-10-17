<?php

declare(strict_types=1);

namespace App\Model\Combat;

use App\Enum\Combat\InterceptionOutcome;

/**
 * Entrée d'un groupe de flottes (un convoi et son escorte, §4.15) dans un système gardé : issue pour la flotte de tête,
 * et passage ou non du groupe (gardes absents ou anéantis).
 */
final readonly class Interception
{
    public function __construct(
        public InterceptionOutcome $outcome,
        public bool $passed,
    ) {}
}
