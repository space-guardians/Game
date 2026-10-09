<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Enum\Combat\CombatSide;
use App\Model\Combat\CombatGroup;
use App\Service\Fleet\Formations;

/**
 * Groupes de combat d'un camp à partir de ses flottes (§4.7) : chaque case occupée de la formation de chaque flotte
 * devient un groupe, avec les technologies armement / bouclier / coque de l'empire de la flotte. Les flottes d'un
 * même camp se fusionnent case par case (même grille), chacune gardant ses groupes pour l'attribution des pertes.
 */
final readonly class CombatGroups
{
    public function __construct(
        private Formations $formations,
        private CombatRules $rules,
    ) {}

    /**
     * @param list<Fleet> $fleets
     *
     * @return list<CombatGroup>
     */
    public function of(CombatSide $side, array $fleets): array
    {
        $groups = [];
        foreach ($fleets as $fleet) {
            $levels = $this->levels($fleet->getEmpire());
            foreach ($this->formations->of($fleet)->getSlots() as $slot) {
                $type = $slot->getType();
                if ($slot->getQuantity() <= 0) {
                    continue;
                }
                $groups[] = new CombatGroup(
                    CombatGroup::keyOf($fleet->getId(), $type->getCode(), $slot->getRow(), $slot->getColumn()),
                    $side,
                    $fleet->getId(),
                    $type->getCode(),
                    $type->getName(),
                    $type->getShipClass()?->getCode(),
                    $slot->getRow(),
                    $slot->getColumn(),
                    $slot->getQuantity(),
                    $this->rules->withTechnology($type->getAttack(), $levels[CombatRules::WEAPONS] ?? 0),
                    $this->rules->withTechnology($type->getShield(), $levels[CombatRules::SHIELDING] ?? 0),
                    // Une coque nulle n'aurait pas de sens : au moins 1 point de structure
                    max(1.0, $this->rules->withTechnology($type->getStructure(), $levels[CombatRules::ARMOUR] ?? 0)),
                );
            }
        }

        return $groups;
    }

    /** @return array<string, int> niveaux de l'empire, par code de technologie */
    private function levels(Empire $empire): array
    {
        $levels = [];
        foreach ($empire->getResearches() as $research) {
            $levels[$research->getTechnology()->getCode()] = $research->getLevel();
        }

        return $levels;
    }
}
