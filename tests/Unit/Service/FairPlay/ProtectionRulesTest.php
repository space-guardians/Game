<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FairPlay;

use App\Service\FairPlay\ProtectionRules;
use PHPUnit\Framework\TestCase;

/**
 * Protections (§4.13) : bouclier débutant limité dans le temps et par le score, durée minimale des vacances.
 */
final class ProtectionRulesTest extends TestCase
{
    public function testNewbieShieldEndsWithTimeOrScore(): void
    {
        $rules = new ProtectionRules();
        $founded = new \DateTimeImmutable('2026-10-10 12:00');

        self::assertTrue($rules->isNewbie($founded, 100, new \DateTimeImmutable('2026-10-15 12:00')));
        self::assertFalse($rules->isNewbie($founded, ProtectionRules::NEWBIE_SCORE, new \DateTimeImmutable('2026-10-15 12:00')));
        self::assertFalse($rules->isNewbie($founded, 100, new \DateTimeImmutable('2026-10-17 12:00')));
        self::assertEquals(new \DateTimeImmutable('2026-10-17 12:00'), $rules->newbieShieldEnds($founded));
    }

    public function testVacationLastsAtLeastTwoDays(): void
    {
        self::assertEquals(new \DateTimeImmutable('2026-10-17 12:00'), new ProtectionRules()->vacationEndsEarliest(new \DateTimeImmutable('2026-10-15 12:00')));
    }
}
