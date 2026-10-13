<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Alliance;

use App\Service\Alliance\TeleportRules;
use PHPUnit\Framework\TestCase;

/**
 * Délai de transit entre deux portes de téléportation (§4.10).
 */
final class TeleportRulesTest extends TestCase
{
    public function testTheLeastDevelopedGateSetsThePace(): void
    {
        $rules = new TeleportRules();

        self::assertSame(TeleportRules::BASE_TRANSIT_SECONDS, $rules->transitSeconds(1, 1));
        self::assertSame(TeleportRules::BASE_TRANSIT_SECONDS, $rules->transitSeconds(5, 1));
        self::assertSame(intdiv(TeleportRules::BASE_TRANSIT_SECONDS, 3), $rules->transitSeconds(3, 4));
        self::assertSame(TeleportRules::MIN_TRANSIT_SECONDS, $rules->transitSeconds(100, 100));
    }
}
