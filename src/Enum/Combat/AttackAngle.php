<?php

declare(strict_types=1);

namespace App\Enum\Combat;

use App\Enum\Fleet\FormationRow;

/**
 * Angle sous lequel un camp est attaqué (§4.7) : de face, de flanc ou par l'arrière. Il fixe la ligne de sa formation
 * engagée en premier ; à noter dans le rapport de combat.
 */
enum AttackAngle: string
{
    case Front = 'front';
    case Flank = 'flank';
    case Rear = 'rear';

    public function label(): string
    {
        return match ($this) {
            self::Front => 'De face',
            self::Flank => 'De flanc',
            self::Rear => 'Par l’arrière',
        };
    }

    /**
     * Ordre d'engagement des lignes : de face, l'avant d'abord ; par l'arrière, l'arrière d'abord ; de flanc,
     * l'attaque coupe la formation par le milieu.
     *
     * @return list<FormationRow>
     */
    public function rowOrder(): array
    {
        return match ($this) {
            self::Front => [FormationRow::Front, FormationRow::Middle, FormationRow::Back],
            self::Flank => [FormationRow::Middle, FormationRow::Front, FormationRow::Back],
            self::Rear => [FormationRow::Back, FormationRow::Middle, FormationRow::Front],
        };
    }
}
