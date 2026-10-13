<?php

declare(strict_types=1);

namespace App\Enum\Ranking;

/**
 * Grandeurs suivies dans les instantanés d'empire (§5.6.1), avec leur colonne en base.
 */
enum ProgressMetric: string
{
    case Total = 'total';
    case Economy = 'economy';
    case Research = 'research';
    case Military = 'military';
    case Exploration = 'exploration';
    case Production = 'production';
    case Ships = 'ships';
    case Planets = 'planets';

    public function label(): string
    {
        return match ($this) {
            self::Total => 'Score global',
            self::Economy => 'Score économie',
            self::Research => 'Score recherche',
            self::Military => 'Score militaire',
            self::Exploration => 'Score exploration',
            self::Production => 'Production horaire',
            self::Ships => 'Vaisseaux',
            self::Planets => 'Planètes',
        };
    }
}
