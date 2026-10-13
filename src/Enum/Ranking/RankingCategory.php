<?php

declare(strict_types=1);

namespace App\Enum\Ranking;

/**
 * Classements (§4.11) : global (score cumulé) et par catégorie.
 */
enum RankingCategory: string
{
    case Global = 'global';
    case Economy = 'economie';
    case Research = 'recherche';
    case Military = 'militaire';
    case Exploration = 'exploration';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Global',
            self::Economy => 'Économie',
            self::Research => 'Recherche',
            self::Military => 'Militaire',
            self::Exploration => 'Exploration',
        };
    }

    /** Colonne de `EmpireScore` qui porte les points de la catégorie */
    public function field(): string
    {
        return match ($this) {
            self::Global => 'total',
            self::Economy => 'economy',
            self::Research => 'research',
            self::Military => 'military',
            self::Exploration => 'exploration',
        };
    }
}
