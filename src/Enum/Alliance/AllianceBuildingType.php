<?php

declare(strict_types=1);

namespace App\Enum\Alliance;

use App\Model\Economy\Resources;

/**
 * Bâtiments uniques des planètes d'alliance (§4.10), introuvables sur les planètes classiques. Coûts provisoires
 * (§7) : coût de base × 2^(niveau − 1), payé sur le stock de la planète d'alliance. Leurs effets arrivent avec leurs
 * issues : scanner (#58), porte de téléportation (#59).
 */
enum AllianceBuildingType: string
{
    case Scanner = 'scanner';
    case TeleportGate = 'teleport_gate';

    public function label(): string
    {
        return match ($this) {
            self::Scanner => 'Scanner d’alliance',
            self::TeleportGate => 'Porte de téléportation',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Scanner => 'Détecte les flottes présentes ou en déplacement autour de la planète et alerte les membres.',
            self::TeleportGate => 'Relie deux portes : une flotte entre par l’une et ressort par l’autre après un délai, intouchable pendant le transit.',
        };
    }

    /** Coût du niveau donné */
    public function cost(int $level): Resources
    {
        $base = match ($this) {
            self::Scanner => new Resources(20_000, 40_000, 10_000),
            self::TeleportGate => new Resources(100_000, 100_000, 50_000),
        };

        return $base->times(2 ** (max(1, $level) - 1));
    }
}
