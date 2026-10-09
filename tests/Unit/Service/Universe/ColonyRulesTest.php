<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Universe;

use App\Model\Universe\ColonySlots;
use App\Service\Universe\ColonyRules;
use PHPUnit\Framework\TestCase;

final class ColonyRulesTest extends TestCase
{
    public function testOneColonyPerTwoAstrophysicsLevelsRoundedUp(): void
    {
        $rules = new ColonyRules();

        self::assertSame([0, 1, 1, 2, 2, 3, 3, 4], array_map($rules->maxColonies(...), range(0, 7)));
        self::assertSame(0, $rules->maxColonies(-1));
    }

    public function testLevelOpeningTheNextColony(): void
    {
        $rules = new ColonyRules();

        self::assertSame([1, 3, 5, 7], array_map($rules->levelFor(...), range(0, 3)));
        foreach (range(0, 5) as $colonies) {
            // Ce niveau ouvre exactement l'emplacement suivant
            self::assertSame($colonies + 1, $rules->maxColonies($rules->levelFor($colonies)));
            self::assertSame($colonies, $rules->maxColonies($rules->levelFor($colonies) - 1));
        }
    }

    public function testSlotsRefuseBeyondTheFreeOnes(): void
    {
        $slots = new ColonySlots(colonies: 1, max: 2, astrophysicsLevel: 3, nextLevel: 3);

        self::assertSame(1, $slots->free());
        self::assertNull($slots->refusal());
        self::assertNotNull($slots->refusal(2));
    }
}
