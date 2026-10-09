<?php

declare(strict_types=1);

namespace App\Model\Combat;

use App\Enum\Combat\CombatSide;

/** Issue d'un combat (§4.7) : rounds joués, survivants de chaque groupe, vainqueur */
final readonly class CombatResult
{
    /**
     * @param list<CombatGroup>  $groups    groupes au début du combat
     * @param array<string, int> $survivors vaisseaux restants, par clé de groupe
     * @param list<CombatRound>  $rounds
     */
    public function __construct(
        public array $groups,
        public array $survivors,
        public array $rounds,
        /** null : match nul (les deux camps détruits, ou tous deux debout après le dernier round) */
        public ?CombatSide $winner,
    ) {}

    public function survivorsOf(string $key): int
    {
        return $this->survivors[$key] ?? 0;
    }

    public function lossesOf(string $key): int
    {
        foreach ($this->groups as $group) {
            if ($group->key === $key) {
                return $group->count - $this->survivorsOf($key);
            }
        }

        return 0;
    }

    /**
     * Pertes d'un camp, par type de vaisseau.
     *
     * @return array<string, int>
     */
    public function lossesBySide(CombatSide $side): array
    {
        $losses = [];
        foreach ($this->groups as $group) {
            $lost = $group->count - $this->survivorsOf($group->key);
            if ($group->side === $side && $lost > 0) {
                $losses[$group->typeCode] = ($losses[$group->typeCode] ?? 0) + $lost;
            }
        }

        return $losses;
    }

    /**
     * Pertes d'une flotte, par type de vaisseau, pour les retirer de la flotte.
     *
     * @return array<string, int>
     */
    public function lossesOfFleet(int $fleetId): array
    {
        $losses = [];
        foreach ($this->groups as $group) {
            $lost = $group->count - $this->survivorsOf($group->key);
            if ($group->fleetId === $fleetId && $lost > 0) {
                $losses[$group->typeCode] = ($losses[$group->typeCode] ?? 0) + $lost;
            }
        }

        return $losses;
    }

    public function isDestroyed(CombatSide $side): bool
    {
        foreach ($this->groups as $group) {
            if ($group->side === $side && $this->survivorsOf($group->key) > 0) {
                return false;
            }
        }

        return true;
    }
}
