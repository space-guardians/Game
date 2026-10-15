<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\NpcBehaviorProfile;
use App\Model\Economy\Resources;
use App\Service\Combat\PillageRules;
use PHPUnit\Framework\TestCase;

/**
 * Paramétrage PvE (§4.14) : compositions saisies dans le panneau, récompense de victoire, part du butin.
 */
final class NpcBehaviorProfileTest extends TestCase
{
    public function testCompositionsAreReadFromAndWrittenToText(): void
    {
        $profile = new NpcBehaviorProfile();
        $profile->setFleetCompositionText(' light_fighter:15 , cruiser : 3 ');
        $profile->setDefenseCompositionText('');
        $profile->setVictoryMetal(500);

        self::assertSame(['light_fighter' => 15, 'cruiser' => 3], $profile->getFleetComposition());
        self::assertSame('light_fighter:15, cruiser:3', $profile->getFleetCompositionText());
        self::assertSame([], $profile->getDefenseComposition());
        self::assertEquals(new Resources(500.0), $profile->getVictoryReward());
    }

    public function testAnUnreadableCompositionKeepsTheReadablePart(): void
    {
        $profile = new NpcBehaviorProfile();
        $profile->setFleetCompositionText('light_fighter:15, croiseurs, cruiser:0');

        self::assertSame(['light_fighter' => 15], $profile->getFleetComposition());
    }

    public function testLootRatioIsAdjustable(): void
    {
        $rules = new PillageRules();
        $stock = new Resources(1000.0, 1000.0, 0.0);

        self::assertEquals(new Resources(500.0, 500.0), $rules->loot($stock, 10_000.0));
        self::assertEquals(new Resources(300.0, 300.0), $rules->loot($stock, 10_000.0, 0.3));
    }
}
