<?php

declare(strict_types=1);

namespace App\Service\Balance;

use App\Enum\Combat\AttackAngle;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Model\Balance\CombatSimulation;
use App\Model\Combat\CombatGroup;
use App\Repository\ClassMatchupRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Combat\CombatEngine;
use App\Service\Combat\CombatRules;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Simulations de combat pour la passe d'équilibrage (§7) : deux compositions s'affrontent N fois avec le moteur du jeu,
 * le contenu et la matrice des classes en base, technologies au niveau donné, le défenseur pris sous un angle
 * d'attaque. Militaires en ligne avant, civils en ligne arrière. Graine fixe : résultats reproductibles.
 */
final readonly class CombatSimulator
{
    public function __construct(
        private CombatEngine $engine,
        private CombatRules $rules,
        private ShipTypeRepository $shipTypes,
        private ClassMatchupRepository $matchups,
    ) {}

    /**
     * @param array<string, int> $attackers quantités par code de type
     * @param array<string, int> $defenders
     *
     * @throws \InvalidArgumentException type inconnu
     */
    public function simulate(array $attackers, array $defenders, int $runs = 100, AttackAngle $angle = AttackAngle::Front, int $technology = 0, int $seed = 1): CombatSimulation
    {
        $groups = [...$this->groups(CombatSide::Attacker, $attackers, $technology), ...$this->groups(CombatSide::Defender, $defenders, $technology)];
        $matrix = $this->matchups->matrix();
        $randomizer = new Randomizer(new Mt19937($seed));
        $engagement = [CombatSide::Attacker->value => AttackAngle::Front->rowOrder(), CombatSide::Defender->value => $angle->rowOrder()];
        $initial = [CombatSide::Attacker->value => array_sum($attackers), CombatSide::Defender->value => array_sum($defenders)];
        [$wins, $rounds, $lost] = [[CombatSide::Attacker->value => 0, CombatSide::Defender->value => 0], 0, [CombatSide::Attacker->value => 0.0, CombatSide::Defender->value => 0.0]];

        for ($run = 0; $run < max(1, $runs); ++$run) {
            $result = $this->engine->resolve($groups, $matrix, $randomizer, $engagement);
            if (null !== $result->winner) {
                ++$wins[$result->winner->value];
            }
            $rounds += \count($result->rounds);
            foreach ($groups as $group) {
                $lost[$group->side->value] += $group->count - $result->survivorsOf($group->key);
            }
        }
        $runs = max(1, $runs);

        return new CombatSimulation(
            $runs,
            $wins[CombatSide::Attacker->value],
            $wins[CombatSide::Defender->value],
            $rounds / $runs,
            $initial[CombatSide::Attacker->value] > 0 ? $lost[CombatSide::Attacker->value] / $runs / $initial[CombatSide::Attacker->value] : 0.0,
            $initial[CombatSide::Defender->value] > 0 ? $lost[CombatSide::Defender->value] / $runs / $initial[CombatSide::Defender->value] : 0.0,
        );
    }

    /**
     * @param array<string, int> $composition
     *
     * @return list<CombatGroup>
     */
    private function groups(CombatSide $side, array $composition, int $technology): array
    {
        $groups = [];
        foreach ($composition as $code => $quantity) {
            $type = $this->shipTypes->findOneByCode($code) ?? throw new \InvalidArgumentException(\sprintf('Type de vaisseau inconnu : « %s ».', $code));
            $row = $type->isMilitary() || $type->isDefense() ? FormationRow::Front : FormationRow::Back;
            $groups[] = new CombatGroup(
                \sprintf('%s:%s', $side->value, $code),
                $side,
                null,
                $code,
                $type->getName(),
                $type->getShipClass()?->getCode(),
                $row,
                FormationColumn::Center,
                $quantity,
                $this->rules->withTechnology($type->getAttack(), $technology),
                $this->rules->withTechnology($type->getShield(), $technology),
                max(1.0, $this->rules->withTechnology($type->getStructure(), $technology)),
            );
        }

        return $groups;
    }
}
