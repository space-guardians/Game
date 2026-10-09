<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Combat;

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
        $this->rules = new EngagementRules(new CombatRules());
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

    public function testOnlyTwoMovingSidesLeaveRoomForAnAngle(): void
    {
        // Angle d'attaque entre flottes convergentes (#44) : de front tant qu'il n'est pas calculé
        $orders = $this->rules->orders(SideMotion::moving(1.0, 0.0), SideMotion::moving(-1.0, 0.0));

        self::assertSame(self::FRONTAL, $orders[CombatSide::Defender->value]);
    }
}
