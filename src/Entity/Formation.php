<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Model\Fleet\FormationCell;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Formation d'une flotte (§4.7) : répartition de ses vaisseaux sur une grille avant / milieu / arrière × gauche /
 * centre / droite, qui décide de l'ordre d'engagement en combat. Chaque vaisseau de la flotte y occupe une case.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'formation_fleet_unique', fields: ['fleet'])]
#[Auditable]
final class Formation implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, FormationSlot> */
    #[ORM\OneToMany(targetEntity: FormationSlot::class, mappedBy: 'formation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $slots;

    public function __construct(
        #[ORM\OneToOne(inversedBy: 'formation')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Fleet $fleet,
    ) {
        $this->slots = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFleet(): Fleet
    {
        return $this->fleet;
    }

    /** @return Collection<int, FormationSlot> */
    public function getSlots(): Collection
    {
        return $this->slots;
    }

    /**
     * Remplace la répartition (contrôlée au préalable par FormationRules) ; les cases vides ne sont pas enregistrées.
     *
     * @param list<FormationCell>     $cells
     * @param array<string, ShipType> $types types de la flotte, par code
     */
    public function arrange(array $cells, array $types): void
    {
        $this->slots->clear();
        foreach ($cells as $cell) {
            if ($cell->quantity > 0) {
                $this->slots->add(new FormationSlot($this, $cell->row, $cell->column, $types[$cell->ship], $cell->quantity));
            }
        }
    }

    public function quantity(FormationRow $row, FormationColumn $column, ShipType $type): int
    {
        $count = 0;
        foreach ($this->slots as $slot) {
            if ($slot->getRow() === $row && $slot->getColumn() === $column && $slot->getType() === $type) {
                $count += $slot->getQuantity();
            }
        }

        return $count;
    }

    /** Vaisseaux dans une case, tous types confondus */
    public function total(FormationRow $row, FormationColumn $column): int
    {
        $count = 0;
        foreach ($this->slots as $slot) {
            if ($slot->getRow() === $row && $slot->getColumn() === $column) {
                $count += $slot->getQuantity();
            }
        }

        return $count;
    }

    public function __toString(): string
    {
        return 'Formation de ' . $this->fleet->getName();
    }
}
