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

    public function testDetectionGrowsWithProbesAndTheTargetsLead(): void
    {
        $rules = new EspionageRules();

        self::assertEqualsWithDelta(0.02, $rules->detectionChance(3, 3, 1), 1e-9);
        self::assertEqualsWithDelta(0.2, $rules->detectionChance(3, 3, 10), 1e-9);
        // Cible en avance de 3 niveaux : × 8 ; en retard de 2 : ÷ 4
        self::assertEqualsWithDelta(0.16, $rules->detectionChance(0, 3, 1), 1e-9);
        self::assertEqualsWithDelta(0.005, $rules->detectionChance(5, 3, 1), 1e-9);
        self::assertSame(1.0, $rules->detectionChance(0, 10, 5));
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
