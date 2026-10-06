<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Fleet;

use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Model\Fleet\FormationCell;
use App\Service\Fleet\FormationRules;
use PHPUnit\Framework\TestCase;

final class FormationRulesTest extends TestCase
{
    public function testDefaultPutsMilitaryInFrontAndCivilInTheBack(): void
    {
        $cells = new FormationRules()->defaultLayout([
            'light_fighter' => ['quantity' => 10, 'military' => true],
            'small_cargo' => ['quantity' => 3, 'military' => false],
            'recycler' => ['quantity' => 0, 'military' => false],
        ]);

        self::assertEquals([
            new FormationCell(FormationRow::Front, FormationColumn::Center, 'light_fighter', 10),
            new FormationCell(FormationRow::Back, FormationColumn::Center, 'small_cargo', 3),
        ], $cells);
    }

    public function testValidLayoutPlacesEveryShipOnce(): void
    {
        $rules = new FormationRules();

        self::assertSame([], $rules->violations(['light_fighter' => 10, 'small_cargo' => 3], [
            new FormationCell(FormationRow::Front, FormationColumn::Left, 'light_fighter', 4),
            new FormationCell(FormationRow::Front, FormationColumn::Right, 'light_fighter', 6),
            new FormationCell(FormationRow::Back, FormationColumn::Center, 'small_cargo', 3),
        ]));
    }

    public function testReportsMissingExtraAndForeignShips(): void
    {
        $violations = new FormationRules()->violations(
            ['light_fighter' => 10, 'small_cargo' => 3],
            [
                new FormationCell(FormationRow::Front, FormationColumn::Center, 'light_fighter', 12),
                new FormationCell(FormationRow::Back, FormationColumn::Center, 'cruiser', 1),
            ],
            ['light_fighter' => 'Chasseur léger', 'small_cargo' => 'Transporteur léger', 'cruiser' => 'Croiseur'],
        );

        self::assertSame([
            '« Croiseur » ne fait pas partie de la flotte.',
            'Chasseur léger : 12 placé(s) sur 10.',
            'Transporteur léger : 0 placé(s) sur 3.',
        ], $violations);
    }

    public function testNegativeQuantityIsRejected(): void
    {
        $violations = new FormationRules()->violations(['light_fighter' => 2], [
            new FormationCell(FormationRow::Front, FormationColumn::Center, 'light_fighter', 4),
            new FormationCell(FormationRow::Middle, FormationColumn::Center, 'light_fighter', -2),
        ]);

        self::assertSame(['Nombre négatif en middle-center : -2.'], $violations);
    }
}
