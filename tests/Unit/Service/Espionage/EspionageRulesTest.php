<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Espionage;

use App\Service\Espionage\EspionageRules;
use PHPUnit\Framework\TestCase;

final class EspionageRulesTest extends TestCase
{
    public function testScoreWeighsTechnologiesAndExtraProbes(): void
    {
        $rules = new EspionageRules();

        self::assertSame(0, $rules->score(2, 2, 1));
        self::assertSame(3, $rules->score(5, 2, 1));
        self::assertSame(-2, $rules->score(0, 3, 2));
        self::assertSame(0, $rules->score(1, 1, 0));
    }

    public function testSectionsAreRevealedStepByStep(): void
    {
        $rules = new EspionageRules();

        self::assertSame(['resources'], $rules->revealed(-5));
        self::assertSame(['resources', 'fleets'], $rules->revealed(0));
        self::assertSame(['resources', 'fleets', 'defenses', 'buildings'], $rules->revealed(2));
        self::assertSame(['resources', 'fleets', 'defenses', 'buildings', 'research'], $rules->revealed(10));
    }
}
