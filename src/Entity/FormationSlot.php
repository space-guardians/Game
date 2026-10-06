<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Case occupée de la grille de formation : un nombre de vaisseaux d'un type.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'formation_slot_unique', fields: ['formation', 'row', 'column', 'type'])]
#[Auditable]
final class FormationSlot implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'slots')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Formation $formation,
        #[ORM\Column(name: 'grid_row', length: 10, enumType: FormationRow::class)]
        private readonly FormationRow $row,
        #[ORM\Column(name: 'grid_column', length: 10, enumType: FormationColumn::class)]
        private readonly FormationColumn $column,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private readonly ShipType $type,
        #[ORM\Column]
        private readonly int $quantity,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Une case occupée compte au moins un vaisseau.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormation(): Formation
    {
        return $this->formation;
    }

    public function getRow(): FormationRow
    {
        return $this->row;
    }

    public function getColumn(): FormationColumn
    {
        return $this->column;
    }

    public function getType(): ShipType
    {
        return $this->type;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function __toString(): string
    {
        return \sprintf('%s %s : %d × %s', $this->row->label(), $this->column->label(), $this->quantity, $this->type->getName());
    }
}
