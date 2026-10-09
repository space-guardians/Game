<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Combat;

use App\Model\Combat\MatchupMatrix;
use PHPUnit\Framework\TestCase;

final class MatchupMatrixTest extends TestCase
{
    public function testMissingPairsAndClasslessShipsAreNeutral(): void
    {
        $matrix = new MatchupMatrix(['interceptor' => ['bomber' => 1.5]]);

        self::assertSame(1.5, $matrix->multiplier('interceptor', 'bomber'));
        // Pas symétrique : la paire inverse reste neutre tant qu'elle n'est pas réglée
        self::assertSame(1.0, $matrix->multiplier('bomber', 'interceptor'));
        self::assertSame(1.0, $matrix->multiplier(null, 'bomber'));
        self::assertSame(1.0, $matrix->multiplier('interceptor', null));
    }
}
