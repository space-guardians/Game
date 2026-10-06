<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Fleet;

use App\Model\Economy\Resources;
use App\Service\Fleet\ShipyardRules;
use PHPUnit\Framework\TestCase;

final class ShipyardRulesTest extends TestCase
{
    public function testUnitDurationDependsOnShipyardNanitesAndSpeed(): void
    {
        $rules = new ShipyardRules();
        $lightFighter = new Resources(3000, 1000);

        // 4 000 / (2 500 × (1 + chantier) × 2^nanites) heures
        self::assertSame(2880, $rules->unitSeconds($lightFighter, 1, 0, 1.0));
        self::assertSame(1152, $rules->unitSeconds($lightFighter, 4, 0, 1.0));
        self::assertSame(576, $rules->unitSeconds($lightFighter, 4, 1, 1.0));
        self::assertSame(288, $rules->unitSeconds($lightFighter, 4, 1, 2.0));
        self::assertSame(1, $rules->unitSeconds(new Resources(), 1, 0, 1.0));
    }

    public function testOneMoreSlotEveryFourLevels(): void
    {
        $rules = new ShipyardRules();

        self::assertSame([0, 1, 1, 1, 2, 2, 2, 2, 3], array_map($rules->slots(...), range(0, 8)));
    }

    public function testNewOrderGoesToTheSlotFreedFirst(): void
    {
        $rules = new ShipyardRules();
        $now = new \DateTimeImmutable('2026-10-06 10:00:00');

        // Poste libre : tout de suite, le plus petit numéro
        self::assertEquals([0, $now], $rules->nextSlot(2, [], $now));
        self::assertEquals([1, $now], $rules->nextSlot(2, [0 => new \DateTimeImmutable('2026-10-06 11:00:00')], $now));
        // Tous occupés : celui qui se libère le plus tôt
        self::assertEquals(
            [1, new \DateTimeImmutable('2026-10-06 10:30:00')],
            $rules->nextSlot(2, [0 => new \DateTimeImmutable('2026-10-06 11:00:00'), 1 => new \DateTimeImmutable('2026-10-06 10:30:00')], $now),
        );
        // Une commande déjà terminée ne retarde pas
        self::assertEquals([0, $now], $rules->nextSlot(1, [0 => new \DateTimeImmutable('2026-10-06 09:00:00')], $now));
    }

    public function testNoSlotWithoutShipyard(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ShipyardRules()->nextSlot(0, [], new \DateTimeImmutable());
    }
}
