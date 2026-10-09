<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Combat;

use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Model\Combat\CombatGroup;
use App\Model\Combat\MatchupMatrix;
use App\Service\Combat\CombatEngine;
use App\Service\Combat\CombatRules;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class CombatEngineTest extends TestCase
{
    private CombatEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new CombatEngine(new CombatRules());
    }

    public function testOverwhelmingSideWinsAndLossesAreDeterministicForASeed(): void
    {
        $groups = [
            $this->group('a', CombatSide::Attacker, 50, attack: 50, shield: 10, hull: 400),
            $this->group('d', CombatSide::Defender, 5, attack: 50, shield: 10, hull: 400),
        ];

        $result = $this->engine->resolve($groups, new MatchupMatrix(), $this->randomizer());

        self::assertSame(CombatSide::Attacker, $result->winner);
        self::assertSame(0, $result->survivorsOf('d'));
        self::assertTrue($result->isDestroyed(CombatSide::Defender));
        self::assertLessThan(CombatRules::MAX_ROUNDS, \count($result->rounds));
        self::assertEquals($result, $this->engine->resolve($groups, new MatchupMatrix(), $this->randomizer()));
    }

    public function testCombatStopsAfterTheLastRoundWithADraw(): void
    {
        // Coques énormes : personne ne tombe
        $result = $this->engine->resolve([
            $this->group('a', CombatSide::Attacker, 2, attack: 10, shield: 0, hull: 1_000_000),
            $this->group('d', CombatSide::Defender, 2, attack: 10, shield: 0, hull: 1_000_000),
        ], new MatchupMatrix(), $this->randomizer());

        self::assertNull($result->winner);
        self::assertCount(CombatRules::MAX_ROUNDS, $result->rounds);
        self::assertSame(2, $result->survivorsOf('a'));
    }

    public function testDestructionIsDeterministicOnceDamageIsKnown(): void
    {
        // 10 tirs de 100 sans bouclier, chacun au but ou non : chaque tranche de 250 détruit un vaisseau
        $result = $this->engine->resolve([
            $this->group('a', CombatSide::Attacker, 10, attack: 100, shield: 0, hull: 1_000_000),
            $this->group('d', CombatSide::Defender, 100, attack: 0, shield: 0, hull: 250),
        ], new MatchupMatrix(), $this->randomizer());

        $first = $result->rounds[0];
        self::assertSame(10, $first->shotsBy(CombatSide::Attacker));
        self::assertSame($first->hitsBy(CombatSide::Attacker) * 100.0, $first->damageBy(CombatSide::Attacker));
        self::assertSame(intdiv($first->hitsBy(CombatSide::Attacker) * 100, 250), $first->losses['d'] ?? 0);
    }

    public function testClassMatrixMultipliesDamage(): void
    {
        $matrix = new MatchupMatrix(['interceptor' => ['bomber' => 2.0]]);
        $groups = [
            $this->group('a', CombatSide::Attacker, 100, attack: 10, shield: 0, hull: 1_000_000, class: 'interceptor'),
            $this->group('d', CombatSide::Defender, 1, attack: 0, shield: 0, hull: 1_000_000, class: 'bomber'),
        ];

        $neutral = $this->engine->resolve($groups, new MatchupMatrix(), $this->randomizer())->rounds[0];
        $boosted = $this->engine->resolve($groups, $matrix, $this->randomizer())->rounds[0];

        // Même graine, mêmes tirs au but : dégâts doublés
        self::assertSame($neutral->hitsBy(CombatSide::Attacker), $boosted->hitsBy(CombatSide::Attacker));
        self::assertEqualsWithDelta(2 * $neutral->damageBy(CombatSide::Attacker), $boosted->damageBy(CombatSide::Attacker), 1e-6);
    }

    public function testFrontRowTakesMostOfTheFireAndRearRowIsProtected(): void
    {
        $result = $this->engine->resolve([
            $this->group('a', CombatSide::Attacker, 1000, attack: 10, shield: 0, hull: 1_000_000),
            $this->group('front', CombatSide::Defender, 10, attack: 0, shield: 0, hull: 1_000_000, row: FormationRow::Front),
            $this->group('back', CombatSide::Defender, 10, attack: 0, shield: 0, hull: 1_000_000, row: FormationRow::Back),
        ], new MatchupMatrix(), $this->randomizer());

        // Lignes avant et arrière seules occupées : l'avant, engagé en premier, reçoit 2/3 des tirs et l'arrière 1/3,
        // avec 30 % de dégâts en moins. Coques énormes : aucune perte.
        $round = $result->rounds[0];
        self::assertSame(1000, $round->shotsBy(CombatSide::Attacker));
        self::assertSame(10, $result->survivorsOf('front'));
        // Dégâts : ≈ 2/3 × 1 000 × 0,8 × 10 sur l'avant, 1/3 × 1 000 × 0,8 × 10 × 0,7 sur l'arrière
        self::assertEqualsWithDelta(2 / 3 * 8000 + 1 / 3 * 8000 * 0.7, $round->damageBy(CombatSide::Attacker), 400);
    }

    public function testShieldsRegenerateEachRoundAndStopWeakFleets(): void
    {
        // Attaque 5 contre bouclier 100 : ricoche, aucun dégât
        $result = $this->engine->resolve([
            $this->group('a', CombatSide::Attacker, 500, attack: 0.5, shield: 0, hull: 1000),
            $this->group('d', CombatSide::Defender, 1, attack: 0, shield: 100, hull: 10),
        ], new MatchupMatrix(), $this->randomizer());

        self::assertSame(1, $result->survivorsOf('d'));
        self::assertSame(0.0, $result->rounds[0]->damageBy(CombatSide::Attacker));
    }

    public function testCustomEngagementOrderExposesTheRearFirst(): void
    {
        $groups = [
            $this->group('a', CombatSide::Attacker, 100, attack: 300, shield: 0, hull: 1_000_000),
            $this->group('front', CombatSide::Defender, 50, attack: 0, shield: 0, hull: 500, row: FormationRow::Front),
            $this->group('back', CombatSide::Defender, 50, attack: 0, shield: 0, hull: 500, row: FormationRow::Back),
        ];

        $frontal = $this->engine->resolve($groups, new MatchupMatrix(), $this->randomizer())->rounds[0];
        $fromBehind = $this->engine->resolve($groups, new MatchupMatrix(), $this->randomizer(), [
            CombatSide::Defender->value => [FormationRow::Back, FormationRow::Middle, FormationRow::Front],
        ])->rounds[0];

        self::assertGreaterThan($frontal->losses['back'] ?? 0, $fromBehind->losses['back'] ?? 0);
        self::assertGreaterThan($fromBehind->losses['front'] ?? 0, $frontal->losses['front'] ?? 0);
    }

    public function testLossesAreAttributedToEachFleet(): void
    {
        $result = $this->engine->resolve([
            $this->group('a', CombatSide::Attacker, 200, attack: 100, shield: 0, hull: 1_000_000),
            new CombatGroup('f1', CombatSide::Defender, 1, 'light_fighter', 'Chasseur', null, FormationRow::Front, FormationColumn::Left, 5, 0, 0, 100),
            new CombatGroup('f2', CombatSide::Defender, 2, 'light_fighter', 'Chasseur', null, FormationRow::Front, FormationColumn::Right, 5, 0, 0, 100),
        ], new MatchupMatrix(), $this->randomizer());

        self::assertSame(CombatSide::Attacker, $result->winner);
        self::assertSame(['light_fighter' => 5], $result->lossesOfFleet(1));
        self::assertSame(['light_fighter' => 5], $result->lossesOfFleet(2));
        self::assertSame(['light_fighter' => 10], $result->lossesBySide(CombatSide::Defender));
        self::assertSame([], $result->lossesBySide(CombatSide::Attacker));
    }

    public function testDuplicateGroupKeysAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->resolve([
            $this->group('a', CombatSide::Attacker, 1),
            $this->group('a', CombatSide::Defender, 1),
        ], new MatchupMatrix(), $this->randomizer());
    }

    private function group(string $key, CombatSide $side, int $count, float $attack = 10, float $shield = 10, float $hull = 100, ?string $class = null, FormationRow $row = FormationRow::Front): CombatGroup
    {
        return new CombatGroup($key, $side, null, 'ship', 'Vaisseau', $class, $row, FormationColumn::Center, $count, $attack, $shield, $hull);
    }

    private function randomizer(): Randomizer
    {
        return new Randomizer(new Mt19937(42));
    }
}
