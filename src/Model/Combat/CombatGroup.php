<?php

declare(strict_types=1);

namespace App\Model\Combat;

use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;

/**
 * Groupe de vaisseaux identiques engagés dans un combat (§4.7) : un type de vaisseau d'une flotte, sur une case de la
 * formation de son camp. Caractéristiques par vaisseau, technologies de son empire déjà appliquées (CombatRules).
 * Plusieurs flottes d'un même camp se fusionnent case par case : chaque groupe garde sa flotte d'origine.
 */
final readonly class CombatGroup
{
    public function __construct(
        /** Identifiant stable dans le combat (flotte, type, case), pour attribuer les pertes */
        public string $key,
        public CombatSide $side,
        public ?int $fleetId,
        public string $typeCode,
        public string $typeName,
        /** Classe de combat (matrice §4.7) ; null pour un vaisseau civil, toujours neutre */
        public ?string $classCode,
        public FormationRow $row,
        public FormationColumn $column,
        public int $count,
        public float $attack,
        public float $shield,
        public float $hull,
    ) {
        if ($count < 0 || $hull <= 0) {
            throw new \InvalidArgumentException(\sprintf('Groupe « %s » invalide : nombre positif et coque non nulle requis.', $key));
        }
    }

    /** Clé de groupe conventionnelle : flotte, type, case */
    public static function keyOf(?int $fleetId, string $typeCode, FormationRow $row, FormationColumn $column): string
    {
        return \sprintf('%s:%s:%s-%s', $fleetId ?? '-', $typeCode, $row->value, $column->value);
    }
}
