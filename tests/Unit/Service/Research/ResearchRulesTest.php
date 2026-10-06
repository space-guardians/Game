<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research;

use App\Entity\Technology;
use App\Model\Economy\Resources;
use App\Service\Research\ResearchRules;
use PHPUnit\Framework\TestCase;

final class ResearchRulesTest extends TestCase
{
    public function testCostGrowsWithFactorPerLevel(): void
    {
        $astrophysics = new Technology('astrophysics', 'Astrophysique');
        $astrophysics->setBaseCost(new Resources(4000, 8000, 4000));
        $astrophysics->setCostFactor(1.75);

        $rules = new ResearchRules();

        self::assertEquals(new Resources(4000, 8000, 4000), $rules->cost($astrophysics, 1));
        self::assertEquals(new Resources(7000, 14000, 7000), $rules->cost($astrophysics, 2));
        self::assertEquals(new Resources(12250, 24500, 12250), $rules->cost($astrophysics, 3));
    }

    public function testDurationDependsOnLaboratoriesAndUniverseSpeed(): void
    {
        $rules = new ResearchRules();
        $energy = new Resources(0, 800, 400);

        // 800 / (1 000 × (1 + labos)) heures ; le deutérium ne compte pas
        self::assertSame(2880, $rules->durationSeconds($energy, 0, 1.0));
        self::assertSame(1440, $rules->durationSeconds($energy, 1, 1.0));
        self::assertSame(720, $rules->durationSeconds($energy, 3, 1.0));
        self::assertSame(360, $rules->durationSeconds($energy, 3, 2.0));
        self::assertSame(1, $rules->durationSeconds(new Resources(), 0, 1.0));
    }

    public function testLevelStartsAtOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ResearchRules()->cost(new Technology('energy', 'Énergie'), 0);
    }
}
