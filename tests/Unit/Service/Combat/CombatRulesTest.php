<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Combat;

use App\Enum\Fleet\FormationRow;
use App\Service\Combat\CombatRules;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class CombatRulesTest extends TestCase
{
    private CombatRules $rules;

    protected function setUp(): void
    {
        $this->rules = new CombatRules();
    }

    public function testTechnologyAddsTenPercentPerLevel(): void
    {
        self::assertSame(100.0, $this->rules->withTechnology(100, 0));
        self::assertEqualsWithDelta(150.0, $this->rules->withTechnology(100, 5), 1e-9);
    }

    public function testFireSharesFollowTheEngagementOrderOfOccupiedRows(): void
    {
        self::assertEqualsWithDelta(
            ['front' => 0.6, 'middle' => 0.3, 'back' => 0.1],
            $this->rules->rowShares($this->rules->frontalOrder()),
            1e-9,
        );
        // Ligne avant détruite : le milieu prend la tête (60 %), l'arrière suit (30 %), renormalisés
        self::assertEqualsWithDelta(['middle' => 2 / 3, 'back' => 1 / 3], $this->rules->rowShares([FormationRow::Middle, FormationRow::Back]), 1e-9);
        self::assertSame(['back' => 1.0], $this->rules->rowShares([FormationRow::Back]));
    }

    public function testSplitKeepsEveryUnit(): void
    {
        self::assertSame(['a' => 6, 'b' => 3, 'c' => 1], $this->rules->split(10, ['a' => 0.6, 'b' => 0.3, 'c' => 0.1]));
        self::assertSame(['a' => 1, 'b' => 1, 'c' => 0], $this->rules->split(2, ['a' => 1, 'b' => 1, 'c' => 1]));
        self::assertSame(7, array_sum($this->rules->split(7, ['a' => 0.33, 'b' => 0.33, 'c' => 0.34])));
        self::assertSame(['a' => 0], $this->rules->split(0, ['a' => 1]));
    }

    public function testHitsFollowAccuracyAndAreReproducible(): void
    {
        $hits = $this->rules->hits(1000, new Randomizer(new Mt19937(1)));

        self::assertEqualsWithDelta(800, $hits, 50);
        self::assertSame($hits, $this->rules->hits(1000, new Randomizer(new Mt19937(1))));
        self::assertSame(0, $this->rules->hits(0, new Randomizer(new Mt19937(1))));
        // Grands nombres : approximation normale, bornée
        $many = $this->rules->hits(1_000_000, new Randomizer(new Mt19937(2)));
        self::assertEqualsWithDelta(800_000, $many, 3000);
    }

    public function testShieldsAbsorbPerShipHitAndWeakShotsBounce(): void
    {
        // 4 tirs de 30 sur 10 vaisseaux de bouclier 20 : chaque vaisseau touché arrête 20
        self::assertSame(['hull' => 40.0, 'absorbed' => 80.0], $this->rules->hullDamage([['hits' => 4, 'damage' => 30.0]], 10, 20));
        // 4 tirs sur 2 vaisseaux : seuls 2 boucliers jouent
        self::assertSame(['hull' => 80.0, 'absorbed' => 40.0], $this->rules->hullDamage([['hits' => 4, 'damage' => 30.0]], 2, 20));
        // Tir sous 1 % du bouclier : ricoche
        self::assertSame(['hull' => 0.0, 'absorbed' => 50.0], $this->rules->hullDamage([['hits' => 50, 'damage' => 1.0]], 1, 200));
    }
}
