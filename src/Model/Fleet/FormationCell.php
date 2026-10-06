<?php

declare(strict_types=1);

namespace App\Model\Fleet;

use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;

/**
 * Case de la grille de formation et nombre de vaisseaux d'un type qui l'occupent.
 */
final readonly class FormationCell
{
    public function __construct(
        public FormationRow $row,
        public FormationColumn $column,
        /** Code du type de vaisseau */
        public string $ship,
        public int $quantity,
    ) {}

    public function key(): string
    {
        return $this->row->value . '-' . $this->column->value;
    }
}
