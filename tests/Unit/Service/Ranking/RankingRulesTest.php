<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Ranking;

use App\Model\Economy\Resources;
use App\Service\Ranking\RankingRules;
use PHPUnit\Framework\TestCase;

/**
 * Règles des scores (§4.11) : ressources investies, points par catégorie, score global pondéré.
 */
final class RankingRulesTest extends TestCase
{
    private RankingRules $rules;

    protected function setUp(): void
    {
        $this->rules = new RankingRules();
    }

    public function testLevelsCostAddsEveryLevelReached(): void
    {
        $base = new Resources(60, 15, 0);

        self::assertSame(0.0, $this->rules->levelsCost($base, 1.5, 0));
        // 75 + 112,5 + 168,75
        self::assertEqualsWithDelta(356.25, $this->rules->levelsCost($base, 1.5, 3), 1e-9);
        // Sans progression : le même coût à chaque niveau
        self::assertEqualsWithDelta(300.0, $this->rules->levelsCost($base, 1.0, 4), 1e-9);
    }

    public function testUnitsCost(): void
    {
        self::assertSame(8_000.0 * 3, $this->rules->unitsCost(new Resources(6_000, 2_000, 0), 3));
        self::assertSame(0.0, $this->rules->unitsCost(new Resources(6_000, 2_000, 0), -1));
    }

    public function testScoreIsTheWeightedSumOfCategories(): void
    {
        $score = $this->rules->score(12_500.0, 3_999.0, 48_000.0, 2, 3);

        self::assertSame(12, $score->economy);
        self::assertSame(3, $score->research);
        self::assertSame(48, $score->military);
        self::assertSame(2 * RankingRules::POINTS_PER_COLONY + 3 * RankingRules::POINTS_PER_EXPLORATION, $score->exploration);
        self::assertSame(12 + 3 + 48 + $score->exploration, $score->total);
    }
}
