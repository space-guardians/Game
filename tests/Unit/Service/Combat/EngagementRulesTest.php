<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Combat;

use App\Enum\Combat\AttackAngle;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Model\Combat\CombatGroup;
use App\Model\Combat\MatchupMatrix;
use App\Model\Combat\SideMotion;
use App\Service\Combat\CombatEngine;
use App\Service\Combat\CombatRules;
use App\Service\Combat\EngagementRules;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class EngagementRulesTest extends TestCase
{
    private const array FRONTAL = [FormationRow::Front, FormationRow::Middle, FormationRow::Back];

    private EngagementRules $rules;

    protected function setUp(): void
    {
        $this->rules = new EngagementRules();
    }

    public function testStationedDefenderAlwaysFacesTheAttacker(): void
    {
        // Quelle que soit la direction d'arrivée de l'attaquant, la cible à l'arrêt lui fait face
        foreach ([[1.0, 0.0], [-1.0, 0.0], [0.0, 1.0], [0.3, -0.9]] as [$x, $y]) {
            $orders = $this->rules->orders(SideMotion::moving($x, $y), SideMotion::stationary());

            self::assertSame(self::FRONTAL, $orders[CombatSide::Defender->value]);
            self::assertSame(self::FRONTAL, $orders[CombatSide::Attacker->value]);
        }
    }

    public function testFrontRowOfAStationedFleetIsEngagedFirst(): void
    {
        $groups = [
            new CombatGroup('a', CombatSide::Attacker, null, 'cruiser', 'Croiseur', null, FormationRow::Front, FormationColumn::Center, 100, 300, 0, 1_000_000),
            new CombatGroup('front', CombatSide::Defender, null, 'cruiser', 'Croiseur', null, FormationRow::Front, FormationColumn::Center, 20, 0, 0, 500),
            new CombatGroup('back', CombatSide::Defender, null, 'small_cargo', 'Transporteur', null, FormationRow::Back, FormationColumn::Center, 20, 0, 0, 500),
        ];
        // Arrivée par l'arrière de la cible : sans effet sur une flotte stationnée
        $orders = $this->rules->orders(SideMotion::moving(-1.0, 0.0), SideMotion::stationary());

        $round = new CombatEngine(new CombatRules())->resolve($groups, new MatchupMatrix(), new Randomizer(new Mt19937(7)), $orders)->rounds[0];

        self::assertGreaterThan($round->losses['back'] ?? 0, $round->losses['front'] ?? 0);
    }

    public function testConvergingFleetsCompareTheirApproachVectors(): void
    {
        // Le défenseur file vers l'est (1, 0) ; l'adversaire arrive de la direction opposée à son propre cap
        $east = SideMotion::moving(1.0, 0.0);

        // Adversaire venant de l'est, cap ouest : en face du défenseur
        self::assertSame(AttackAngle::Front, $this->rules->angle($east, SideMotion::moving(-1.0, 0.0)));
        // Adversaire venant de l'ouest, cap est (même direction) : il rattrape le défenseur par l'arrière
        self::assertSame(AttackAngle::Rear, $this->rules->angle($east, SideMotion::moving(1.0, 0.0)));
        // Adversaire venant du nord, cap sud : de flanc
        self::assertSame(AttackAngle::Flank, $this->rules->angle($east, SideMotion::moving(0.0, -1.0)));
        // Bornes : 45° encore de face, 135° déjà par l'arrière
        self::assertSame(AttackAngle::Front, $this->rules->angle($east, SideMotion::moving(-1.0, -1.0)));
        self::assertSame(AttackAngle::Rear, $this->rules->angle($east, SideMotion::moving(1.0, 1.0)));
        self::assertSame(AttackAngle::Flank, $this->rules->angle($east, SideMotion::moving(-0.2, -1.0)));
    }

    public function testEachSideGetsItsOwnAngle(): void
    {
        // L'attaquant (cap est) rattrape le défenseur (cap est, plus loin) : le défenseur est pris par l'arrière, et
        // l'attaquant, lui, voit son adversaire devant lui
        $angles = $this->rules->angles(SideMotion::moving(1.0, 0.0), SideMotion::moving(1.0, 0.0));

        self::assertSame(AttackAngle::Rear, $angles[CombatSide::Defender->value]);
        self::assertSame(AttackAngle::Rear, $angles[CombatSide::Attacker->value]);
        self::assertSame([FormationRow::Back, FormationRow::Middle, FormationRow::Front], $this->rules->orders(SideMotion::moving(1.0, 0.0), SideMotion::moving(1.0, 0.0))[CombatSide::Defender->value]);
    }

    public function testFlankAttackCutsThroughTheMiddleRow(): void
    {
        self::assertSame([FormationRow::Middle, FormationRow::Front, FormationRow::Back], AttackAngle::Flank->rowOrder());
    }

    public function testUnknownHeadingIsFrontal(): void
    {
        self::assertSame(AttackAngle::Front, $this->rules->angle(new SideMotion(false), SideMotion::moving(1.0, 0.0)));
        self::assertSame(AttackAngle::Front, $this->rules->angle(SideMotion::moving(0.0, 0.0), SideMotion::moving(1.0, 0.0)));
    }
}
