<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationRow;
use App\Model\Combat\CombatGroup;
use App\Model\Combat\CombatResult;
use App\Model\Combat\CombatRound;
use App\Model\Combat\MatchupMatrix;
use Random\Randomizer;

/**
 * Résolution d'un combat entre deux camps (§4.7), service pur : des rounds de tirs simultanés jusqu'à la destruction
 * d'un camp ou au dernier round (CombatRules). Les deux camps tirent sur l'état du début du round ; les dégâts
 * s'appliquent ensuite. Hormis la précision (Randomizer), tout est déterministe : même graine, même combat.
 */
final readonly class CombatEngine
{
    public function __construct(
        private CombatRules $rules,
    ) {}

    /**
     * @param list<CombatGroup>                       $groups      groupes des deux camps
     * @param array<string, list<FormationRow>>|null $engagement  ordre dans lequel les lignes de chaque camp sont
     *                                                              engagées, par valeur de CombatSide ; de front par
     *                                                              défaut (§4.7)
     */
    public function resolve(array $groups, MatchupMatrix $matrix, Randomizer $randomizer, ?array $engagement = null): CombatResult
    {
        $byKey = [];
        $count = [];
        $damage = [];
        foreach ($groups as $group) {
            if (isset($byKey[$group->key])) {
                throw new \InvalidArgumentException(\sprintf('Groupe « %s » en double.', $group->key));
            }
            $byKey[$group->key] = $group;
            $count[$group->key] = $group->count;
            $damage[$group->key] = 0.0;
        }

        $rounds = [];
        for ($number = 1; $number <= CombatRules::MAX_ROUNDS; ++$number) {
            if (!$this->standing($groups, $count, CombatSide::Attacker) || !$this->standing($groups, $count, CombatSide::Defender)) {
                break;
            }
            $incoming = [];
            $shots = [];
            $hits = [];
            foreach (CombatSide::cases() as $side) {
                $shots[$side->value] = 0;
                $hits[$side->value] = 0;
                $targets = $this->targets($groups, $count, $side->opponent(), $engagement[$side->opponent()->value] ?? $this->rules->frontalOrder());
                foreach ($groups as $shooter) {
                    if ($shooter->side !== $side || $count[$shooter->key] <= 0) {
                        continue;
                    }
                    $shots[$side->value] += $count[$shooter->key];
                    foreach ($this->rules->split($count[$shooter->key], $targets) as $targetKey => $fired) {
                        $landed = $this->rules->hits($fired, $randomizer);
                        if ($landed <= 0) {
                            continue;
                        }
                        $target = $byKey[$targetKey];
                        $hits[$side->value] += $landed;
                        $incoming[$targetKey][] = [
                            'hits' => $landed,
                            'damage' => $shooter->attack * $matrix->multiplier($shooter->classCode, $target->classCode) * $this->rules->rowProtection($target->row),
                        ];
                    }
                }
            }

            // Dégâts appliqués après les tirs des deux camps
            $absorbed = [CombatSide::Attacker->value => 0.0, CombatSide::Defender->value => 0.0];
            $dealt = [CombatSide::Attacker->value => 0.0, CombatSide::Defender->value => 0.0];
            $losses = [];
            foreach ($incoming as $key => $received) {
                $target = $byKey[$key];
                $result = $this->rules->hullDamage($received, $count[$key], $target->shield);
                $shooterSide = $target->side->opponent()->value;
                $absorbed[$shooterSide] += $result['absorbed'];
                $dealt[$shooterSide] += $result['hull'];
                $damage[$key] += $result['hull'];
                $destroyed = min($count[$key], (int) floor($damage[$key] / $target->hull + 1e-9));
                if ($destroyed > 0) {
                    $count[$key] -= $destroyed;
                    $damage[$key] = 0 === $count[$key] ? 0.0 : $damage[$key] - $destroyed * $target->hull;
                    $losses[$key] = $destroyed;
                }
            }
            $rounds[] = new CombatRound($number, $shots, $hits, $absorbed, $dealt, $losses);
        }

        $attackers = $this->standing($groups, $count, CombatSide::Attacker);
        $defenders = $this->standing($groups, $count, CombatSide::Defender);
        $winner = match (true) {
            $attackers && !$defenders => CombatSide::Attacker,
            $defenders && !$attackers => CombatSide::Defender,
            default => null,
        };

        return new CombatResult($groups, $count, $rounds, $winner);
    }

    /**
     * Cibles d'un camp tireur : poids de chaque groupe adverse = part de sa ligne × sa part des vaisseaux de la ligne.
     *
     * @param list<CombatGroup>  $groups
     * @param array<string, int> $count
     * @param list<FormationRow> $order  ordre d'engagement des lignes du camp visé
     *
     * @return array<string, float> par clé de groupe
     */
    private function targets(array $groups, array $count, CombatSide $side, array $order): array
    {
        $rows = [];
        foreach ($groups as $group) {
            if ($group->side === $side && $count[$group->key] > 0) {
                $rows[$group->row->value][$group->key] = $count[$group->key];
            }
        }
        $occupied = array_values(array_filter($order, static fn(FormationRow $row): bool => isset($rows[$row->value])));
        $weights = [];
        foreach ($this->rules->rowShares($occupied) as $row => $share) {
            $ships = array_sum($rows[$row]);
            foreach ($rows[$row] as $key => $shipsInGroup) {
                $weights[$key] = $share * $shipsInGroup / $ships;
            }
        }

        return $weights;
    }

    /**
     * @param list<CombatGroup>  $groups
     * @param array<string, int> $count
     */
    private function standing(array $groups, array $count, CombatSide $side): bool
    {
        foreach ($groups as $group) {
            if ($group->side === $side && $count[$group->key] > 0) {
                return true;
            }
        }

        return false;
    }
}
